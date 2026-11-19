<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service Sécurisé de Réinitialisation des Mots de Passe Développeurs (Section 11)
 */
class PasswordResetService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Réinitialise le mot de passe d'un développeur dans toute la CDB
     * Règles absolues :
     * 1. Génération cryptographique
     * 2. Expiration immédiate (PASSWORD EXPIRE)
     * 3. AUCUN mot de passe stocké en clair ou dans les logs
     * 4. Révélation unique
     */
    public function resetPassword(string $username, string $dbaUser, ?string $clientIp = null, ?string $customPassword = null): string
    {
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

        // Validation ou génération du mot de passe
        if (!empty($customPassword)) {
            $errors = zirioam_validate_oracle_password($customPassword);
            if (!empty($errors)) {
                throw new \InvalidArgumentException(implode(' ', $errors));
            }
            $tempPassword = $customPassword;
        } else {
            $tempPassword = zirioam_generate_secure_password(14);
        }

        // Bascule obligatoire dans CDB$ROOT pour appliquer CONTAINER = ALL
        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        // Exécution en 2 modes selon spécification (Section 11) :
        // Mode A : Package PL/SQL C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS (avec privilèges AUTHID DEFINER de C##ZIRIOAM_ADMIN)
        // Mode B : Ordre SQL direct ALTER USER si le package n'est pas accessible
        $executedViaPackage = false;
        try {
            $pkgSql = "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.reset_password(:username, :pwd, :dba, :ip); END;";
            $this->db->execute($pkgSql, [
                'username' => $norm,
                'pwd' => $tempPassword,
                'dba' => $dbaUser,
                'ip' => $clientIp,
            ]);
            $executedViaPackage = true;
        } catch (\Throwable $pkgErr) {
            // Mode B : Fallback via commande DDL directe
            $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
            $this->db->executeDdl($sql);
        }

        // Journalisation PHP complémentaire si l'opération a été exécutée via Mode B
        if (!$executedViaPackage) {
            try {
                $logSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_PWD_RESET_LOG 
                           (USERNAME, ORACLE_USER, RESET_BY_DBA, CLIENT_IP, STATUS, RESET_AT)
                           VALUES (:user, :ora, :dba, :ip, 'SUCCESS', SYSTIMESTAMP)";
                $this->db->execute($logSql, [
                    'user' => $norm,
                    'ora' => $oracleUser,
                    'dba' => $dbaUser,
                    'ip' => $clientIp,
                ]);

                // Audit
                $auditSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT 
                             (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS, CLIENT_IP)
                             VALUES (SYSTIMESTAMP, :dba, 'PASSWORD_RESET', :target, 'Réinitialisation mot de passe temporaire (expiré)', 'SUCCESS', :ip)";
                $this->db->execute($auditSql, [
                    'dba' => $dbaUser,
                    'target' => $oracleUser,
                    'ip' => $clientIp,
                ]);
            } catch (\Throwable $e) {
                // Même si le log échoue, l'ordre DDL a été exécuté par Oracle
            }
        }

        return $tempPassword;
    }
}

