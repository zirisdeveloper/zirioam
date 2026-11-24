<?php
namespace App\Services;

use App\Core\App;
use App\Security\Validator;

/**
 * Service Autonome de Changement de Mot de Passe Développeur (Self-Service)
 * Permet à un développeur de mettre à jour son propre mot de passe
 * avec application globale (CONTAINER = ALL) sur l'ensemble de la CDB et des PDBs.
 */
class SelfServicePasswordService
{
    private ServerRegistryService $serverRegistry;

    public function __construct(?ServerRegistryService $serverRegistry = null)
    {
        $this->serverRegistry = $serverRegistry ?: new ServerRegistryService();
    }

    /**
     * Vérifie la validité des identifiants actuels (compte Oracle + mot de passe actuel)
     * auprès de la base de données cible avant de permettre la saisie du nouveau mot de passe.
     *
     * @return array
     */
    public function verifyCredentials(string $serverId, string $username, string $currentPassword): array
    {
        // 1. Validation du serveur
        $server = $this->serverRegistry->getServerById($serverId);
        if (!$server) {
            return [
                'valid' => false,
                'message' => "La base de données sélectionnée est introuvable ou inactive.",
            ];
        }

        // 2. Normalisation et validation de l'identifiant
        $norm = strtoupper(trim($username));
        if (empty($norm)) {
            return [
                'valid' => false,
                'message' => "Veuillez saisir votre identifiant développeur.",
            ];
        }

        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;
        if (!zirioam_is_valid_oracle_identifier($oracleUser)) {
            return [
                'valid' => false,
                'message' => "Identifiant Oracle non valide : '{$oracleUser}'. L'identifiant ne doit contenir que des lettres, chiffres, _ ou #.",
            ];
        }

        // 3. Validation du mot de passe saisi
        if (empty($currentPassword)) {
            return [
                'valid' => false,
                'message' => "Veuillez saisir votre mot de passe actuel.",
            ];
        }

        $host = $server['ip_address'];
        $port = (int)$server['port'];
        $rootService = $server['cdb_services'][0]['root_service'] ?? $server['cdb_services'][0]['cdb_name'] ?? 'dev16';
        $easyConnect = "//{$host}:{$port}/{$rootService}";

        // 4. Test d'authentification direct auprès d'Oracle
        if (function_exists('oci_connect')) {
            $conn = @oci_connect($oracleUser, $currentPassword, $easyConnect);
            if ($conn) {
                @oci_close($conn);
                return [
                    'valid' => true,
                    'oracle_user' => $oracleUser,
                    'server_id' => $serverId,
                    'server_name' => $server['server_name'],
                    'server_endpoint' => "{$host}:{$port}",
                    'server_service' => $rootService,
                    'server_env' => $server['environment'] ?? '',
                    'message' => "Identifiants vérifiés avec succès.",
                ];
            }

            $err = oci_error();
            $code = (int)($err['code'] ?? 0);
            $msg = $err['message'] ?? '';

            // ORA-01017: invalid username/password; logon denied
            if ($code === 1017) {
                return [
                    'valid' => false,
                    'message' => "Mot de passe actuel incorrect ou identifiant introuvable. Veuillez réessayer.",
                ];
            }

            // ORA-28000: the account is locked
            if ($code === 28000) {
                return [
                    'valid' => false,
                    'message' => "Le compte Oracle {$oracleUser} est actuellement verrouillé (ORA-28000). Veuillez contacter votre administrateur DBA.",
                ];
            }

            // ORA-28001: the password has expired (le mot de passe est valide mais doit être renouvelé)
            // ORA-28002: the password will expire within X days
            // ORA-01045: user lacks CREATE SESSION (mot de passe valide mais pas de session directe)
            if ($code === 28001 || $code === 28002 || $code === 1045) {
                return [
                    'valid' => true,
                    'oracle_user' => $oracleUser,
                    'server_id' => $serverId,
                    'server_name' => $server['server_name'],
                    'server_endpoint' => "{$host}:{$port}",
                    'server_service' => $rootService,
                    'server_env' => $server['environment'] ?? '',
                    'message' => "Identifiants vérifiés avec succès" . ($code === 28001 ? " (mot de passe expiré à renouveler)." : "."),
                ];
            }

            // Erreur de connexion au serveur (ex: timeout, ORA-12541, etc.)
            return [
                'valid' => false,
                'message' => "Impossible d'établir la connexion avec la base Oracle ({$msg}). Veuillez vérifier que le serveur est accessible.",
            ];
        }

        // Si oci_connect n'est pas disponible (environnement de test / mock)
        return [
            'valid' => true,
            'oracle_user' => $oracleUser,
            'server_id' => $serverId,
            'server_name' => $server['server_name'],
            'server_endpoint' => "{$host}:{$port}",
            'server_service' => $rootService,
            'server_env' => $server['environment'] ?? '',
            'message' => "Identifiants vérifiés.",
        ];
    }

    /**
     * Exécute le changement de mot de passe en mode self-service
     */
    public function changePassword(
        string $serverId,
        string $username,
        string $oldPassword,
        string $newPassword,
        string $confirmPassword,
        ?string $clientIp = null,
        ?string $adminUser = null,
        ?string $adminPassword = null
    ): array {
        // 1. Validation de l'existence du serveur dans servers.json
        $server = $this->serverRegistry->getServerById($serverId);
        if (!$server) {
            throw new \InvalidArgumentException("La base de données sélectionnée est introuvable ou inactive.");
        }

        // 2. Normalisation et validation de l'identifiant développeur
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

        if (!zirioam_is_valid_oracle_identifier($oracleUser)) {
            throw new \InvalidArgumentException("Identifiant Oracle non valide : '{$oracleUser}'. L'identifiant ne doit contenir que des lettres, chiffres, _ ou #.");
        }

        // 3. Contrôles stricts sur les mots de passe
        if (empty($oldPassword)) {
            throw new \InvalidArgumentException("L'ancien mot de passe est obligatoire.");
        }

        if (empty($newPassword)) {
            throw new \InvalidArgumentException("Le nouveau mot de passe est obligatoire.");
        }

        if ($newPassword !== $confirmPassword) {
            throw new \InvalidArgumentException("Le nouveau mot de passe et sa confirmation ne correspondent pas.");
        }

        if ($newPassword === $oldPassword) {
            throw new \InvalidArgumentException("Le nouveau mot de passe doit être différent de l'ancien mot de passe.");
        }

        // Validation de complexité Oracle 19c
        $complexityErrors = zirioam_validate_oracle_password($newPassword);
        if (!empty($complexityErrors)) {
            throw new \InvalidArgumentException(implode(' ', $complexityErrors));
        }

        // 4. Détermination des coordonnées de connexion
        $host = $server['ip_address'];
        $port = (int)$server['port'];
        $rootService = $server['cdb_services'][0]['root_service'] ?? $server['cdb_services'][0]['cdb_name'] ?? 'dev16';
        $easyConnect = "//{$host}:{$port}/{$rootService}";

        $executed = false;
        $executionMethod = '';
        $lastException = null;

        // -------------------------------------------------------------
        // STRATÉGIE 1 : Connexion directe développeur à CDB$ROOT (via C##RL_<USER>)
        // & Exécution autonome de ALTER USER ... IDENTIFIED BY ... REPLACE ...
        // Le développeur bénéficie du privilège CREATE SESSION dans CDB$ROOT
        // accordé via son rôle commun C##RL_<USER>. Cette méthode ne nécessite
        // aucun compte administrateur ni mot de passe stocké sur le serveur !
        // -------------------------------------------------------------
        if (function_exists('oci_connect')) {
            $userConn = @oci_connect($oracleUser, $oldPassword, $easyConnect);
            if ($userConn) {
                $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$newPassword}\" REPLACE \"{$oldPassword}\"";
                $stmt = @oci_parse($userConn, $sql);
                if ($stmt && @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
                    $executed = true;
                    $executionMethod = 'USER_DIRECT_REPLACE_IN_CDB_ROOT';
                } else {
                    $ociErr = oci_error($stmt ?: $userConn);
                    $eCode = (int)($ociErr['code'] ?? 0);
                    $eMsg = $ociErr['message'] ?? '';
                    if ($eCode === 28003) {
                        @oci_close($userConn);
                        throw new \InvalidArgumentException(
                            "Le nouveau mot de passe ne respecte pas les critères de complexité définis par Oracle : " . $eMsg
                        );
                    }
                    if ($eCode === 28007) {
                        @oci_close($userConn);
                        throw new \InvalidArgumentException(
                            "Le mot de passe a déjà été utilisé récemment et ne peut pas être réutilisé (ORA-28007)."
                        );
                    }
                    $lastException = new \RuntimeException($eMsg ?: "Erreur Oracle lors de ALTER USER {$eCode}");
                }
                @oci_close($userConn);
            } else {
                $ociErr = oci_error();
                $errCode = (int)($ociErr['code'] ?? 0);
                $errMsg = $ociErr['message'] ?? '';

                if ($errCode === 1017) {
                    throw new \InvalidArgumentException(
                        "Échec d'authentification : L'ancien mot de passe saisi est incorrect pour le compte {$oracleUser}."
                    );
                }
                // Si ORA-28001 (mot de passe expiré), oci_connect échoue mais oci_password_change peut le renouveler ci-dessous
                if ($errCode !== 28001) {
                    $lastException = new \RuntimeException($errMsg ?: "Erreur de connexion Oracle {$errCode}");
                }
            }
        }

        // -------------------------------------------------------------
        // STRATÉGIE 2 : Appel OCI natif oci_password_change()
        // Particulièrement efficace lorsque le mot de passe est expiré (ORA-28001)
        // -------------------------------------------------------------
        if (!$executed && function_exists('oci_password_change')) {
            $conn = @oci_password_change($easyConnect, $oracleUser, $oldPassword, $newPassword);
            if ($conn) {
                if (is_resource($conn)) {
                    @oci_close($conn);
                }
                $executed = true;
                $executionMethod = 'OCI_NATIVE';
            } else {
                $ociErr = oci_error();
                $errCode = (int)($ociErr['code'] ?? 0);
                $errMsg = $ociErr['message'] ?? '';

                if ($errCode === 1017) {
                    throw new \InvalidArgumentException(
                        "Échec d'authentification : L'ancien mot de passe saisi est incorrect pour le compte {$oracleUser}."
                    );
                }
                if ($errCode === 28003) {
                    throw new \InvalidArgumentException(
                        "Le nouveau mot de passe ne respecte pas les critères de complexité définis par Oracle : " . $errMsg
                    );
                }
                if ($errCode === 28007) {
                    throw new \InvalidArgumentException(
                        "Le mot de passe a déjà été utilisé récemment et ne peut pas être réutilisé (ORA-28007)."
                    );
                }
                $lastException = new \RuntimeException($errMsg ?: "Erreur Oracle {$errCode}");
            }
        }

        // -------------------------------------------------------------
        // STRATÉGIE 3 : Relais administratif de secours (Session DBA ou Admin explicite)
        // -------------------------------------------------------------
        if (!$executed) {
            $connService = new OracleConnectionService();
            $activeConn = $connService->getActiveConnection();

            // 1. Validation obligatoire de l'ancien mot de passe pour prévenir toute usurpation
            $credentialsValid = false;
            if (function_exists('oci_connect')) {
                $testConn = @oci_connect($oracleUser, $oldPassword, $easyConnect);
                if ($testConn) {
                    $credentialsValid = true;
                    @oci_close($testConn);
                } else {
                    $testErr = oci_error();
                    $tCode = (int)($testErr['code'] ?? 0);
                    // 1045 = mot de passe correct mais pas de session en root -> Validé !
                    // 28001 = mot de passe expiré mais valide -> Validé !
                    if ($tCode === 1045 || $tCode === 28001) {
                        $credentialsValid = true;
                    } elseif ($tCode === 1017) {
                        throw new \InvalidArgumentException(
                            "Échec d'authentification : L'ancien mot de passe saisi est incorrect pour le compte {$oracleUser}."
                        );
                    }
                }
            } else {
                $credentialsValid = true;
            }

            // 2. Si des identifiants d'administration explicites ont été saisis
            if ($credentialsValid && !empty($adminUser) && !empty($adminPassword)) {
                $aMode = strtoupper(trim($adminUser)) === 'SYS' ? 'SYSDBA' : 'NORMAL';
                try {
                    $explicitConn = new \App\Database\OracleConnection(
                        $host,
                        $port,
                        $rootService,
                        $adminUser,
                        $adminPassword,
                        $aMode
                    );
                    try {
                        $curCon = $explicitConn->getCurrentContainer();
                        if ($curCon !== 'CDB$ROOT') {
                            $explicitConn->switchContainer('CDB$ROOT');
                        }
                        $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$newPassword}\" ACCOUNT UNLOCK CONTAINER = ALL";
                        $explicitConn->executeDdl($sql);
                        $executed = true;
                        $executionMethod = 'EXPLICIT_ADMIN_CREDENTIALS';
                    } finally {
                        $explicitConn->close();
                    }
                } catch (\Throwable $ae) {
                    $lastException = $ae;
                }
            }

            // 3. Exécuter via la connexion DBA active en session
            if (!$executed && $credentialsValid && $activeConn) {
                $origCon = null;
                try {
                    $origCon = $activeConn->getCurrentContainer();
                    if ($origCon !== 'CDB$ROOT') {
                        $activeConn->switchContainer('CDB$ROOT');
                    }
                    $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$newPassword}\" ACCOUNT UNLOCK CONTAINER = ALL";
                    $activeConn->executeDdl($sql);
                    $executed = true;
                    $executionMethod = 'DBA_ACTIVE_SESSION';
                } catch (\Throwable $e) {
                    $lastException = $e;
                } finally {
                    if ($origCon && $origCon !== 'CDB$ROOT') {
                        try {
                            $activeConn->switchContainer($origCon);
                        } catch (\Throwable $ce) {}
                    }
                }
            }

            // 4. Si échec ou droits insuffisants sous Database Vault, tenter via connexion Vault Manager
            if (!$executed && $credentialsValid) {
                try {
                    $vaultConn = $connService->getVaultConnection();
                    if ($vaultConn) {
                        try {
                            $vCon = $vaultConn->getCurrentContainer();
                            if ($vCon !== 'CDB$ROOT') {
                                $vaultConn->switchContainer('CDB$ROOT');
                            }
                            $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$newPassword}\" ACCOUNT UNLOCK CONTAINER = ALL";
                            $vaultConn->executeDdl($sql);
                            $executed = true;
                            $executionMethod = 'VAULT_MANAGER_SESSION';
                        } finally {
                            $vaultConn->close();
                        }
                    }
                } catch (\Throwable $ve) {
                    $lastException = $ve;
                }
            }
        }

        if (!$executed) {
            $msg = $lastException ? $lastException->getMessage() : "Impossible de modifier le mot de passe.";
            throw new \RuntimeException(
                "Échec de la mise à jour du mot de passe : " . $msg . 
                " (Assurez-vous que le rôle C##RL_{$norm} dispose du privilège CREATE SESSION dans CDB\$ROOT en cliquant sur 'Accorder Session CDB\$ROOT' sur la fiche du développeur)."
            );
        }

        // -------------------------------------------------------------
        // VÉRIFICATION DE CERTITUDE ABSOLUE : Tester que le nouveau mot de passe fonctionne
        // -------------------------------------------------------------
        if (function_exists('oci_connect')) {
            $verifyConn = @oci_connect($oracleUser, $newPassword, $easyConnect);
            if ($verifyConn) {
                @oci_close($verifyConn);
            } else {
                $vErr = oci_error();
                $vCode = (int)($vErr['code'] ?? 0);
                // Si le code est 1017, le mot de passe n'a pas été appliqué sur Oracle !
                if ($vCode === 1017) {
                    throw new \RuntimeException(
                        "Échec critique : Le mot de passe n'a pas pu être modifié sur la base Oracle (le compte répond toujours à l'ancien mot de passe)."
                    );
                }
            }
        }

        // 5. Journalisation d'audit facultative (si ZIRIOAM a une connexion DBA active)
        try {
            $connService = new OracleConnectionService();
            $activeConn = $connService->getActiveConnection();
            if ($activeConn) {
                $curCon = $activeConn->getCurrentContainer();
                if ($curCon !== 'CDB$ROOT') {
                    $activeConn->switchContainer('CDB$ROOT');
                }
                $logSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_PWD_RESET_LOG 
                           (USERNAME, ORACLE_USER, RESET_BY_DBA, CLIENT_IP, STATUS, RESET_AT)
                           VALUES (:u, :ora, 'SELF_SERVICE', :ip, 'SUCCESS', SYSTIMESTAMP)";
                $activeConn->execute($logSql, [
                    'u' => $norm,
                    'ora' => $oracleUser,
                    'ip' => $clientIp,
                ]);

                $auditSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT 
                             (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS, CLIENT_IP)
                             VALUES (SYSTIMESTAMP, 'SELF_SERVICE', 'PASSWORD_CHANGE', :ora, 'Changement de mot de passe autonome développeur (CONTAINER=ALL)', 'SUCCESS', :ip)";
                $activeConn->execute($auditSql, [
                    'ora' => $oracleUser,
                    'ip' => $clientIp,
                ]);
            }
        } catch (\Throwable $e) {
            // Log d'audit non bloquant
        }

        return [
            'oracle_user' => $oracleUser,
            'server_id' => $serverId,
            'server_name' => $server['server_name'],
            'execution_method' => $executionMethod,
        ];
    }
}
