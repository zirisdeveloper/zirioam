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

        $executed = false;
        $lastException = null;

        // 1. Tenter EN PRIORITÉ via la connexion d'administration active (SYS / DBA)
        // Note architecturale : Sur la plupart des configurations (y compris avec Database Vault),
        // le compte SYS dispose des droits pour exécuter ALTER USER ... IDENTIFIED BY ... PASSWORD EXPIRE.
        try {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }
            $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
            $this->db->executeDdl($sql);
            $executed = true;
        } catch (\Throwable $sysErr) {
            $lastException = $sysErr;
        }

        // 2. Si échec DDL direct, tenter via le package PL/SQL C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS
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

        // 3. Si échec de la connexion standard (ex: ORA-01031 sous Database Vault)
        // Tenter via la connexion Vault Manager si des identifiants ont été fournis
        if (!$executed && (!empty($explicitVaultPassword) || !empty($explicitVaultUser))) {
            $connService = new \App\Services\OracleConnectionService();
            try {
                $vUser = !empty($explicitVaultUser) ? $explicitVaultUser : 'C##VOLTMANAGER';
                $vaultConn = null;
                if (!empty($explicitVaultPassword)) {
                    $vaultConn = $connService->createCustomConnection($vUser, $explicitVaultPassword);
                } else {
                    $vaultConn = $connService->getVaultConnection();
                }

                if ($vaultConn) {
                    try {
                        $curCon = $vaultConn->getCurrentContainer();
                        if ($curCon !== 'CDB$ROOT') {
                            $vaultConn->switchContainer('CDB$ROOT');
                        }
                        $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
                        $vaultConn->executeDdl($sql);
                        $executed = true;
                    } finally {
                        $vaultConn->close();
                    }
                }
            } catch (\Throwable $ve) {
                $lastException = $ve;
            }
        }

        if (!$executed) {
            $errMsg = $lastException ? $lastException->getMessage() : 'Erreur inconnue';
            if (strpos($errMsg, 'ORA-01031') !== false || strpos($errMsg, 'ORA-474') !== false) {
                throw new \RuntimeException(
                    "Échec de réinitialisation : ORA-01031: Privilèges insuffisants (Database Vault actif). " .
                    "Le compte SYS ne peut pas modifier ce mot de passe. Veuillez renseigner le compte habilité (C##VOLTMANAGER) via les options avancées."
                );
            }
            throw new \RuntimeException("Échec de réinitialisation : " . $errMsg);
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

