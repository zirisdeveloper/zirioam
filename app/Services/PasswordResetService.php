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
     * 2. Mot de passe immédiatement opérationnel (évite ORA-65162 sur PDB si pas d'accès CDB$ROOT)
     * 3. AUCUN mot de passe stocké en clair ou dans les logs
     * 4. Révélation unique
     */
    public function resetPassword(
        string $username,
        string $dbaUser,
        ?string $clientIp = null,
        ?string $customPassword = null,
        ?string $explicitVaultUser = null,
        ?string $explicitVaultPassword = null,
        bool $expirePassword = false
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

        // Si $expirePassword est false, nous omettons 'PASSWORD EXPIRE'.
        // En Oracle Multitenant, un compte commun avec PASSWORD EXPIRE ne peut PAS changer son mot de passe
        // lors d'une connexion directe à une PDB (ORA-65162). Comme les développeurs n'ont pas CREATE SESSION
        // dans CDB$ROOT, le mot de passe doit être actif (OPEN) directement.
        $expireClause = $expirePassword ? ' PASSWORD EXPIRE' : '';
        $ddlCommand = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\"{$expireClause} ACCOUNT UNLOCK CONTAINER = ALL";

        // 1. Tenter EN PRIORITÉ via la connexion d'administration active (SYS / DBA)
        try {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }
            $this->db->executeDdl($ddlCommand);
            $executed = true;
        } catch (\Throwable $sysErr) {
            $lastException = $sysErr;
        }

        // 2. Si échec DDL direct et que PASSWORD EXPIRE est explicitement demandé,
        // tenter via le package PL/SQL C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS
        if (!$executed && $expirePassword) {
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
                        $vaultConn->executeDdl($ddlCommand);
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
            $auditDetails = $expirePassword 
                ? 'Réinitialisation mot de passe (PASSWORD EXPIRE)' 
                : 'Réinitialisation mot de passe actif (compte déverrouillé)';

            $auditSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT 
                         (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS, CLIENT_IP)
                         VALUES (SYSTIMESTAMP, :dba, 'PASSWORD_RESET', :target, :details, 'SUCCESS', :ip)";
            $this->db->execute($auditSql, [
                'dba' => $dbaUser,
                'target' => $oracleUser,
                'details' => $auditDetails,
                'ip' => $clientIp,
            ]);
        } catch (\Throwable $e) {
            // Même si le log échoue, l'ordre DDL a été exécuté par Oracle
        }

        return $tempPassword;
    }
}

