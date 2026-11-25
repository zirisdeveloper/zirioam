<?php
namespace App\Services;

use App\Core\App;
use App\Core\Session;
use App\Database\OracleConnection;
use App\Security\EncryptionService;

/**
 * Service dédié au Portail Self-Service Développeur (/selfservice)
 * Utilise exclusivement les identifiants du développeur connecté (zéro secret administrateur stocké)
 * et s'appuie sur les packages AUTHID DEFINER Oracle (PKG_ZIRIOAM_COMMON_OPS & PKG_ZIRIOAM_DEV_SELFSERVICE).
 */
class DeveloperPortalService
{
    private Session $session;

    public function __construct(?Session $session = null)
    {
        $this->session = $session ?: App::getInstance()->getSession();
    }

    /**
     * Authentifie le développeur sur le serveur Oracle cible et ouvre sa session self-service
     */
    public function authenticate(array $server, string $username, string $password): array
    {
        $norm = strtoupper(trim($username));
        if ($norm === '' || $password === '') {
            throw new \InvalidArgumentException("Veuillez saisir votre nom d'utilisateur et votre mot de passe Oracle.");
        }

        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;
        if (!zirioam_is_valid_oracle_identifier($oracleUser)) {
            throw new \InvalidArgumentException("Identifiant Oracle invalide : '{$oracleUser}'.");
        }

        $host = (string)$server['ip_address'];
        $port = (int)$server['port'];
        $rootService = (string)($server['cdb_services'][0]['root_service'] ?? $server['cdb_services'][0]['cdb_name'] ?? 'dev16');
        $cdbName = (string)($server['cdb_services'][0]['cdb_name'] ?? strtoupper($rootService));

        try {
            $conn = new OracleConnection($host, $port, $rootService, $oracleUser, $password, 'NORMAL');
        } catch (\Throwable $e) {
            $code = method_exists($e, 'getOracleCode') ? (int)$e->getOracleCode() : 0;
            if ($code === 1017) {
                throw new \InvalidArgumentException("Identifiant ou mot de passe Oracle incorrect.");
            }
            if ($code === 28001) {
                throw new \InvalidArgumentException(
                    "Votre mot de passe Oracle a expiré (ORA-28001). Veuillez d'abord le renouveler sur la page /UpdPwd."
                );
            }
            if ($code === 28000) {
                throw new \InvalidArgumentException(
                    "Votre compte Oracle {$oracleUser} est verrouillé (ORA-28000). Veuillez contacter votre administrateur DBA."
                );
            }
            if ($code === 1045) {
                throw new \InvalidArgumentException(
                    "Accès CDB\$ROOT non autorisé pour {$oracleUser} (ORA-01045). Demandez à un administrateur d'activer la session CDB\$ROOT via ZIRIOAM."
                );
            }
            throw $e;
        }

        try {
            $dbRow = $conn->queryOne("SELECT SYS_CONTEXT('USERENV', 'DB_NAME') AS DB_NAME FROM DUAL");
            if (!empty($dbRow['DB_NAME'])) {
                $cdbName = strtoupper((string)$dbRow['DB_NAME']);
            }
        } catch (\Throwable $e) {
            // Conserver le nom CDB configuré dans servers.json
        } finally {
            $conn->close();
        }

        $sessionData = [
            'server' => $server,
            'username' => $oracleUser,
            'password_enc' => EncryptionService::encrypt($password),
            'cdb' => $cdbName,
            'root_service' => $rootService,
            'connected_at' => gmdate('c'),
        ];

        $this->session->regenerate(true);
        $this->session->set('selfservice_session', $sessionData);
        $this->session->set('selfservice_cdb', $cdbName);
        $this->session->remove('selfservice_pdb');
        $this->session->remove('selfservice_pdb_service');

        return $sessionData;
    }

    public function getSessionData(): ?array
    {
        $data = $this->session->get('selfservice_session');
        return is_array($data) && !empty($data['username']) && !empty($data['server']) ? $data : null;
    }

    public function disconnect(): void
    {
        $this->session->remove('selfservice_session');
        $this->session->remove('selfservice_cdb');
        $this->session->remove('selfservice_pdb');
        $this->session->remove('selfservice_pdb_service');
    }

    public function selectPdb(string $pdbName, string $serviceName = ''): void
    {
        $pdbNorm = strtoupper(trim($pdbName));
        if (!zirioam_is_valid_oracle_identifier($pdbNorm)) {
            throw new \InvalidArgumentException("Nom de PDB invalide.");
        }

        $svc = trim($serviceName) !== '' ? trim($serviceName) : strtolower($pdbNorm);
        $this->session->set('selfservice_pdb', $pdbNorm);
        $this->session->set('selfservice_pdb_service', $svc);
    }

    /**
     * Ouvre une connexion Oracle sur CDB$ROOT avec les identifiants du développeur connecté
     */
    private function connectToRoot(): OracleConnection
    {
        $sess = $this->getSessionData();
        if (!$sess) {
            throw new \RuntimeException("Session développeur expirée. Veuillez vous reconnecter.");
        }

        $password = EncryptionService::decrypt((string)$sess['password_enc']);
        if ($password === null) {
            throw new \RuntimeException("Clé de session expirée. Veuillez vous reconnecter.");
        }

        $server = $sess['server'];
        return new OracleConnection(
            (string)$server['ip_address'],
            (int)$server['port'],
            (string)$sess['root_service'],
            (string)$sess['username'],
            $password,
            'NORMAL'
        );
    }

    /**
     * Ouvre une connexion Oracle directement sur une PDB cible avec les identifiants du développeur
     */
    private function connectToPdb(string $pdbName, ?string $serviceName = null): OracleConnection
    {
        $sess = $this->getSessionData();
        if (!$sess) {
            throw new \RuntimeException("Session développeur expirée. Veuillez vous reconnecter.");
        }

        $password = EncryptionService::decrypt((string)$sess['password_enc']);
        if ($password === null) {
            throw new \RuntimeException("Clé de session expirée. Veuillez vous reconnecter.");
        }

        $server = $sess['server'];
        $host = (string)$server['ip_address'];
        $port = (int)$server['port'];
        $username = (string)$sess['username'];
        $rootService = (string)($sess['root_service'] ?? '');

        $candidates = [];
        if (!empty($serviceName)) {
            $candidates[] = trim($serviceName);
        }
        $candidates[] = strtolower($pdbName);
        if (strpos($rootService, '.') !== false) {
            $domain = substr($rootService, strpos($rootService, '.') + 1);
            if ($domain !== '') {
                $candidates[] = strtolower($pdbName) . '.' . $domain;
            }
        }
        $candidates = array_values(array_unique(array_filter($candidates)));

        $lastEx = null;
        foreach ($candidates as $svc) {
            try {
                return new OracleConnection($host, $port, $svc, $username, $password, 'NORMAL');
            } catch (\Throwable $e) {
                $lastEx = $e;
                $code = method_exists($e, 'getOracleCode') ? (int)$e->getOracleCode() : 0;
                if (in_array($code, [1045, 1017, 28000, 28001], true)) {
                    break;
                }
            }
        }

        throw $lastEx ?: new \RuntimeException("Impossible de se connecter à la PDB {$pdbName}.");
    }

    /**
     * Découvre les PDBs ouvertes sur le serveur et vérifie l'accès ZIRIOAM du développeur dans chacune
     *
     * @return array<int, array<string, mixed>>
     */
    public function discoverAccessiblePdbs(): array
    {
        $sess = $this->getSessionData();
        if (!$sess) {
            return [];
        }

        $openPdbs = [];
        $discoveryFailure = null;
        $rootConn = $this->connectToRoot();
        try {
            // 1. Méthode recommandée : Package CDB dédié lecture seule AUTHID DEFINER
            try {
                $openPdbs = $rootConn->queryRefCursor(
                    "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_DEV_DISCOVERY.get_open_pdbs(:cursor); END;"
                );
            } catch (\Throwable $e) {
                // 2. Fallback PKG_ZIRIOAM_COMMON_OPS ou dictionnaire
                try {
                    $openPdbs = $rootConn->queryRefCursor(
                        "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.get_open_pdbs(:cursor); END;"
                    );
                } catch (\Throwable $e1) {
                    try {
                        $openPdbs = $rootConn->query(
                            "SELECT CON_ID, NAME AS PDB_NAME, OPEN_MODE, RESTRICTED, LOWER(NAME) AS SERVICE_NAME
                             FROM V\$PDBS
                             WHERE NAME <> 'PDB\$SEED' AND OPEN_MODE = 'READ WRITE'
                             ORDER BY NAME"
                        );
                    } catch (\Throwable $e2) {
                        try {
                            $openPdbs = $rootConn->query(
                                "SELECT CON_ID, PDB_NAME, 'READ WRITE' AS OPEN_MODE, 'NO' AS RESTRICTED, LOWER(PDB_NAME) AS SERVICE_NAME
                                 FROM ALL_PDBS
                                 WHERE PDB_NAME <> 'PDB\$SEED'
                                 ORDER BY PDB_NAME"
                            );
                        } catch (\Throwable $e3) {
                            $discoveryFailure = $e->getMessage();
                        }
                    }
                }
            }
        } finally {
            $rootConn->close();
        }

        if ($discoveryFailure !== null && empty($openPdbs)) {
            throw new \RuntimeException(
                "Impossible de lister les PDBs depuis CDB\$ROOT ({$discoveryFailure}). " .
                "Demandez à l'administrateur de redéployer les composants CDB\$ROOT via « Maintenance »."
            );
        }

        $result = [];
        foreach ($openPdbs as $pdb) {
            $pdbName = strtoupper(trim((string)($pdb['PDB_NAME'] ?? $pdb['NAME'] ?? '')));
            if ($pdbName === '' || $pdbName === 'PDB$SEED') {
                continue;
            }
            $serviceName = strtolower(trim((string)($pdb['SERVICE_NAME'] ?? $pdbName)));
            $openMode = (string)($pdb['OPEN_MODE'] ?? 'READ WRITE');
            $conId = (int)($pdb['CON_ID'] ?? 0);
            $restricted = strtoupper(trim((string)($pdb['RESTRICTED'] ?? 'NO')));

            $entry = [
                'CON_ID' => $conId,
                'PDB_NAME' => $pdbName,
                'OPEN_MODE' => $openMode,
                'RESTRICTED' => $restricted,
                'SERVICE_NAME' => $serviceName,
                'ACCESSIBLE' => false,
                'ZIRIOAM_DEPLOYED' => true,
                'SCHEMAS' => [],
                'ACCESS_ROWS' => [],
                'STATUS_NOTE' => '',
            ];

            try {
                $pdbConn = $this->connectToPdb($pdbName, $serviceName);
                try {
                    $accessRows = $this->fetchMyAccess($pdbConn, (string)$sess['username']);
                    $entry['ACCESS_ROWS'] = $accessRows;
                    foreach ($accessRows as $ar) {
                        if (!empty($ar['TARGET_SCHEMA'])) {
                            $entry['SCHEMAS'][] = $ar['TARGET_SCHEMA'];
                        }
                    }
                    $entry['SCHEMAS'] = array_values(array_unique($entry['SCHEMAS']));
                    if (!empty($accessRows)) {
                        $entry['ACCESSIBLE'] = true;
                        $entry['STATUS_NOTE'] = count($accessRows) . ' schéma(s) autorisé(s)';
                    } else {
                        $entry['STATUS_NOTE'] = "Aucun schéma attribué dans cette PDB";
                    }
                } catch (\Throwable $ae) {
                    $msg = $ae->getMessage();
                    if (strpos($msg, 'ORA-00942') !== false || strpos($msg, 'ORA-06550') !== false || strpos($msg, 'PLS-00201') !== false || strpos($msg, 'PLS-00302') !== false) {
                        $entry['ZIRIOAM_DEPLOYED'] = false;
                        $entry['STATUS_NOTE'] = "Package Self-Service non déployé sur cette PDB";
                    } else {
                        $entry['STATUS_NOTE'] = $msg;
                    }
                } finally {
                    $pdbConn->close();
                }
            } catch (\Throwable $ce) {
                $entry['STATUS_NOTE'] = "Connexion PDB non autorisée";
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Récupère les accès actifs du développeur dans une PDB spécifique
     */
    public function getMyAccessInPdb(string $pdbName, ?string $serviceName = null): array
    {
        $sess = $this->getSessionData();
        if (!$sess) {
            throw new \RuntimeException("Session développeur expirée.");
        }

        $pdbConn = $this->connectToPdb($pdbName, $serviceName);
        try {
            return $this->fetchMyAccess($pdbConn, (string)$sess['username']);
        } finally {
            $pdbConn->close();
        }
    }

    private function fetchMyAccess(OracleConnection $pdbConn, string $username): array
    {
        try {
            return $pdbConn->queryRefCursor(
                "BEGIN ZIRIOAM_OWNER.PKG_ZIRIOAM_DEV_SELFSERVICE.get_my_access(:cursor); END;"
            );
        } catch (\Throwable $e) {
            // Repli direct si SELECT autorisé sur la table ZIRIOAM_DEVELOPER_ACCESS
            $msg = $e->getMessage();
            if (strpos($msg, 'ORA-06550') !== false || strpos($msg, 'PLS-00201') !== false || strpos($msg, 'PLS-00302') !== false) {
                try {
                    return $pdbConn->query(
                        "SELECT da.TARGET_SCHEMA, ar.ROLE_NAME, ar.PROFILE_TYPE,
                                da.CAN_DDL_CREATE, da.CAN_DDL_ALTER, da.CAN_DDL_DROP, da.STATUS
                         FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                         LEFT JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                         WHERE (da.USERNAME = :u OR ('C##' || da.USERNAME) = :u2)
                           AND da.STATUS = 'ACTIVE'
                         ORDER BY da.TARGET_SCHEMA",
                        ['u' => strtoupper(trim($username)), 'u2' => strtoupper(trim($username))]
                    );
                } catch (\Throwable $e2) {
                    throw $e;
                }
            }
            throw $e;
        }
    }

    /**
     * Synchronise les privilèges sur tous les objets des schémas autorisés pour le développeur dans une PDB
     */
    public function syncPrivilegesInPdb(string $pdbName, ?string $serviceName = null): array
    {
        $pdbName = strtoupper(trim($pdbName));
        $pdbConn = $this->connectToPdb($pdbName, $serviceName);
        try {
            $out = [
                'count' => [0, 32],
                'details' => ['', 4000],
            ];
            $pdbConn->executePlsqlOut(
                "BEGIN ZIRIOAM_OWNER.PKG_ZIRIOAM_DEV_SELFSERVICE.sync_my_privileges(:count, :details); END;",
                $out
            );

            return [
                'pdb' => $pdbName,
                'status' => 'SUCCESS',
                'count' => (int)($out['count'] ?? 0),
                'details' => (string)($out['details'] ?? ''),
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'ORA-06550') !== false || strpos($msg, 'PLS-00201') !== false || strpos($msg, 'PLS-00302') !== false) {
                throw new \RuntimeException(
                    "Le package ZIRIOAM_OWNER.PKG_ZIRIOAM_DEV_SELFSERVICE n'est pas encore déployé sur la PDB {$pdbName}. " .
                    "Demandez à un administrateur ZIRI OAM d'exécuter « Maintenance -> Redéployer » sur cette PDB."
                );
            }
            throw $e;
        } finally {
            $pdbConn->close();
        }
    }

    /**
     * Synchronise les privilèges sur toutes les PDBs accessibles au développeur connecté
     *
     * @return array<int, array<string, mixed>>
     */
    public function syncPrivilegesAllPdbs(): array
    {
        $pdbs = $this->discoverAccessiblePdbs();
        $results = [];

        foreach ($pdbs as $pdb) {
            if (empty($pdb['ACCESSIBLE'])) {
                continue;
            }
            $pdbName = $pdb['PDB_NAME'];
            $svc = $pdb['SERVICE_NAME'] ?? strtolower($pdbName);

            try {
                $results[] = $this->syncPrivilegesInPdb($pdbName, $svc);
            } catch (\Throwable $e) {
                $results[] = [
                    'pdb' => $pdbName,
                    'status' => 'FAILED',
                    'count' => 0,
                    'details' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}

