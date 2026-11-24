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
     * Exécute le changement de mot de passe en mode self-service
     */
    public function changePassword(
        string $serverId,
        string $username,
        string $oldPassword,
        string $newPassword,
        string $confirmPassword,
        ?string $clientIp = null
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
        // STRATÉGIE A : Appel OCI natif oci_password_change()
        // Permet au développeur de changer son propre mot de passe directement
        // au niveau du protocole d'authentification Oracle CDB$ROOT
        // -------------------------------------------------------------
        if (function_exists('oci_password_change')) {
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
                // Si l'erreur est ORA-01045 (lacks CREATE SESSION privilege), le mot de passe a bien été
                // mis à jour dans le dictionnaire central de CDB$ROOT par l'appel OCI, mais la session n'a pas
                // été créée en root (ce qui est parfaitement normal et attendu sous notre isolation stricte PDB).
                if ($errCode === 1045) {
                    $executed = true;
                    $executionMethod = 'OCI_NATIVE_CONTAINER_ISOLATED';
                } else {
                    $lastException = new \RuntimeException($errMsg ?: "Erreur Oracle {$errCode}");
                }
            }
        }

        // -------------------------------------------------------------
        // STRATÉGIE B : Relais via session DBA active ZIRIOAM (si connectée sur ce serveur)
        // -------------------------------------------------------------
        if (!$executed) {
            $connService = new OracleConnectionService();
            $activeConn = $connService->getActiveConnection();
            $sessionServer = App::getInstance()->getSession()->get('current_server');

            if ($activeConn && $sessionServer && ($sessionServer['server_id'] ?? '') === $serverId) {
                // Validation préalable de l'ancien mot de passe pour prévenir toute usurpation
                $credentialsValid = false;
                if (function_exists('oci_connect')) {
                    $testConn = @oci_connect($oracleUser, $oldPassword, $easyConnect);
                    if ($testConn) {
                        $credentialsValid = true;
                        @oci_close($testConn);
                    } else {
                        $testErr = oci_error();
                        $tCode = (int)($testErr['code'] ?? 0);
                        // 1045 = mot de passe bon mais pas de CREATE SESSION en root -> Valide !
                        // 28001 = mot de passe expiré mais valide -> Valide !
                        if ($tCode === 1045 || $tCode === 28001) {
                            $credentialsValid = true;
                        } elseif ($tCode === 1017) {
                            throw new \InvalidArgumentException(
                                "Échec d'authentification : L'ancien mot de passe saisi est incorrect pour le compte {$oracleUser}."
                            );
                        }
                    }
                }

                if ($credentialsValid) {
                    try {
                        $curCon = $activeConn->getCurrentContainer();
                        if ($curCon !== 'CDB$ROOT') {
                            $activeConn->switchContainer('CDB$ROOT');
                        }
                        $sql = "ALTER USER {$oracleUser} IDENTIFIED BY \"{$newPassword}\" ACCOUNT UNLOCK CONTAINER = ALL";
                        $activeConn->executeDdl($sql);
                        $executed = true;
                        $executionMethod = 'DBA_ACTIVE_SESSION';
                    } catch (\Throwable $e) {
                        $lastException = $e;
                    }
                }
            }
        }

        if (!$executed) {
            $msg = $lastException ? $lastException->getMessage() : "Impossible de modifier le mot de passe.";
            throw new \RuntimeException("Échec de la mise à jour du mot de passe : " . $msg);
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
