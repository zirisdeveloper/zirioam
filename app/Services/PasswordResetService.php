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
    public function resetPassword(
        string $username,
        string $dbaUser,
        ?string $clientIp = null,
        ?string $customPassword = null,
        ?string $explicitVaultUser = null,
        ?string $explicitVaultPassword = null
    ): string {
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

        // 1. Tenter en priorité via le compte Vault Manager si disponible (indispensable sous Database Vault)
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = null;

        if (!empty($explicitVaultUser) && !empty($explicitVaultPassword)) {
            $vaultConn = $connService->createCustomConnection($explicitVaultUser, $explicitVaultPassword);
        } else {
            $vaultConn = $connService->getVaultConnection();
        }

        $executed = false;
        $lastException = null;

        if ($vaultConn) {
            try {
                $curCon = $vaultConn->getCurrentContainer();
                if ($curCon !== 'CDB$ROOT') {
                    $vaultConn->switchContainer('CDB$ROOT');
                }
                $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
                $vaultConn->executeDdl($sql);
                $executed = true;
            } catch (\Throwable $ve) {
                $lastException = $ve;
            } finally {
                $vaultConn->close();
            }
        }

        // 2. Si non exécuté via Vault, tenter Mode A : Package PL/SQL C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS
        if (!$executed) {
            try {
                $currentCon = $this->db->getCurrentContainer();
                if ($currentCon !== 'CDB$ROOT') {
                    $this->db->switchContainer('CDB$ROOT');
                }

                $pkgSql = "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.reset_password(:username, :pwd, :dba, :ip); END;";
                $this->db->execute($pkgSql, [
                    'username' => $norm,
                    'pwd' => $tempPassword,
                    'dba' => $dbaUser,
                    'ip' => $clientIp,
                ]);
                $executed = true;
            } catch (\Throwable $pkgErr) {
                $lastException = $pkgErr;
            }
        }

        // 3. Si toujours non exécuté, tenter Mode B : Ordre DDL direct avec la connexion courante
        if (!$executed) {
            try {
                $currentCon = $this->db->getCurrentContainer();
                if ($currentCon !== 'CDB$ROOT') {
                    $this->db->switchContainer('CDB$ROOT');
                }
                $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
                $this->db->executeDdl($sql);
                $executed = true;
            } catch (\Throwable $ddlErr) {
                $errMsg = $ddlErr->getMessage();
                if (strpos($errMsg, 'ORA-01031') !== false || strpos($errMsg, 'ORA-474') !== false) {
                    throw new \RuntimeException(
                        "Échec de réinitialisation : ORA-01031: Privilèges insuffisants (Database Vault actif). " .
                        "Veuillez renseigner les identifiants du compte habilité (C##VOLTMANAGER) dans la fenêtre de réinitialisation."
                    );
                }
                throw $ddlErr;
            }
        }

        // Journalisation dans la table de log ZIRIOAM
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

        return $tempPassword;
    }
}

