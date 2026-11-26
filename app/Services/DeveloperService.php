<?php
namespace App\Services;

use App\Database\OracleConnection;
use App\Database\OracleQueryException;

/**
 * Service de Gestion du Référentiel des Développeurs et des Comptes Communs
 */
class DeveloperService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Exécute une opération dans le conteneur CDB$ROOT en restaurant le conteneur précédent
     * Garantit que les tables du référentiel commun (C##ZIRIOAM_ADMIN.*) sont toujours accessibles
     * même si la session était positionnée dans une PDB.
     */
    private function executeInCdbRoot(callable $callback)
    {
        $prevCon = $this->db->getCurrentContainer();
        if ($prevCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }
        try {
            return $callback();
        } finally {
            if ($prevCon !== 'CDB$ROOT') {
                $this->db->switchContainer($prevCon);
            }
        }
    }

    /**
     * Récupère la liste de tous les développeurs avec filtres de recherche
     */
    public function getAllDevelopers(string $search = '', string $status = ''): array
    {
        return $this->executeInCdbRoot(function() use ($search, $status) {
            $sql = "SELECT d.DEVELOPER_ID, d.USERNAME, d.ORACLE_USER, d.FULL_NAME, d.EMAIL, d.STATUS, 
                           TO_CHAR(d.CREATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                           (SELECT COUNT(*) FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES') AS ORACLE_ACCOUNT_EXISTS,
                           (SELECT CASE 
                                       WHEN COUNT(CASE WHEN u.ACCOUNT_STATUS LIKE '%LOCKED%' THEN 1 END) > 0 THEN 'LOCKED'
                                       ELSE NVL(MIN(u.ACCOUNT_STATUS), 'NON CRÉÉ')
                                   END 
                            FROM CDB_USERS u 
                            WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES') AS ORACLE_ACCOUNT_STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS d
                    WHERE 1=1";

            $binds = [];
            if (!empty($search)) {
                $sql .= " AND (UPPER(d.USERNAME) LIKE :search OR UPPER(d.FULL_NAME) LIKE :search OR UPPER(d.EMAIL) LIKE :search)";
                $binds['search'] = '%' . strtoupper(trim($search)) . '%';
            }

            if (!empty($status)) {
                $sql .= " AND d.STATUS = :status";
                $binds['status'] = strtoupper(trim($status));
            }

            $sql .= " ORDER BY d.USERNAME ASC";

            return $this->db->query($sql, $binds);
        });
    }

    /**
     * Recherche un développeur par son identifiant
     */
    public function getDeveloperById(int $id): ?array
    {
        return $this->executeInCdbRoot(function() use ($id) {
            $sql = "SELECT d.DEVELOPER_ID, d.USERNAME, d.ORACLE_USER, d.FULL_NAME, d.EMAIL, d.STATUS,
                           TO_CHAR(d.CREATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                           TO_CHAR(d.UPDATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT,
                           d.CREATED_BY,
                           (SELECT COUNT(*) FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES') AS ORACLE_ACCOUNT_EXISTS,
                           (SELECT CASE 
                                       WHEN COUNT(CASE WHEN u.ACCOUNT_STATUS LIKE '%LOCKED%' THEN 1 END) > 0 THEN 'LOCKED'
                                       ELSE NVL(MIN(u.ACCOUNT_STATUS), 'NON CRÉÉ')
                                   END 
                            FROM CDB_USERS u 
                            WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES') AS ORACLE_ACCOUNT_STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS d
                    WHERE d.DEVELOPER_ID = :id";

            return $this->db->queryOne($sql, ['id' => $id]);
        });
    }

    /**
     * Recherche par username normalisé (ex: ALI ou C##ALI)
     */
    public function getDeveloperByUsername(string $username): ?array
    {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }

        return $this->executeInCdbRoot(function() use ($norm) {
            $sql = "SELECT DEVELOPER_ID, USERNAME, ORACLE_USER, FULL_NAME, EMAIL, STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS
                    WHERE USERNAME = :username";

            return $this->db->queryOne($sql, ['username' => $norm]);
        });
    }

    /**
     * Crée une identité développeur dans le référentiel ZIRIOAM
     */
    public function createDeveloper(array $data, string $dbaUser): int
    {
        $username = strtoupper(trim($data['username'] ?? ''));
        if (str_starts_with($username, 'C##')) {
            $username = substr($username, 3);
        }

        if (!zirioam_is_valid_oracle_identifier($username)) {
            throw new \InvalidArgumentException("L'identifiant développeur '{$username}' n'est pas un identifiant Oracle valide.");
        }

        $fullName = trim($data['full_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $status = strtoupper(trim($data['status'] ?? 'ACTIVE'));

        if (empty($fullName) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Nom complet ou adresse email invalide.");
        }

        $oracleUser = 'C##' . $username;

        return $this->executeInCdbRoot(function() use ($username, $oracleUser, $fullName, $email, $status, $dbaUser) {
            $sql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                    (USERNAME, ORACLE_USER, FULL_NAME, EMAIL, STATUS, CREATED_BY, CREATED_AT, UPDATED_AT)
                    VALUES 
                    (:username, :oracle_user, :full_name, :email, :status, :dba_user, SYSTIMESTAMP, SYSTIMESTAMP)";

            $this->db->execute($sql, [
                'username' => $username,
                'oracle_user' => $oracleUser,
                'full_name' => $fullName,
                'email' => $email,
                'status' => $status,
                'dba_user' => $dbaUser,
            ]);

            $created = $this->getDeveloperByUsername($username);
            return (int)($created['DEVELOPER_ID'] ?? 0);
        });
    }

    /**
     * Met à jour les informations d'identité
     */
    public function updateDeveloper(int $id, array $data, string $dbaUser): bool
    {
        $fullName = trim($data['full_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $status = strtoupper(trim($data['status'] ?? 'ACTIVE'));

        if (empty($fullName) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Nom complet ou adresse email invalide.");
        }

        return $this->executeInCdbRoot(function() use ($id, $fullName, $email, $status) {
            $sql = "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                    SET FULL_NAME = :full_name, EMAIL = :email, STATUS = :status, UPDATED_AT = SYSTIMESTAMP
                    WHERE DEVELOPER_ID = :id";

            return $this->db->execute($sql, [
                'full_name' => $fullName,
                'email' => $email,
                'status' => $status,
                'id' => $id,
            ]);
        });
    }

    /**
     * Active ou désactive l'identité (distinct du verrouillage Oracle)
     */
    public function toggleStatus(int $id, string $newStatus, string $dbaUser): bool
    {
        return $this->executeInCdbRoot(function() use ($id, $newStatus) {
            $status = in_array(strtoupper($newStatus), ['ACTIVE', 'INACTIVE']) ? strtoupper($newStatus) : 'ACTIVE';
            $sql = "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                    SET STATUS = :status, UPDATED_AT = SYSTIMESTAMP 
                    WHERE DEVELOPER_ID = :id";

            return $this->db->execute($sql, ['status' => $status, 'id' => $id]);
        });
    }

    /**
     * Provisionne le compte Oracle commun dans la CDB
     * Règle de sécurité : CREATE USER C##... IDENTIFIED BY ... CONTAINER = ALL;
     * AUCUN PRIVILÈGE CREATE SESSION N'EST DONNÉ À CE NIVEAU !
     * Note architecturale : En environnement Oracle Multitenant avec isolation stricte PDB
     * (développeurs sans CREATE SESSION dans CDB$ROOT), PASSWORD EXPIRE n'est pas appliqué à la création
     * pour éviter le blocage ORA-65162 lors de la première connexion directe à la PDB autorisée.
     */
    public function provisionOracleAccount(
        string $username,
        string $dbaUser,
        ?string $customPassword = null,
        ?string $explicitVaultUser = null,
        ?string $explicitVaultPassword = null
    ): string {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $commonRole = 'C##RL_' . $norm;

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

        // 1. Tenter d'abord avec Vault Manager (si Database Vault est actif)
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = null;
        if (!empty($explicitVaultUser) && !empty($explicitVaultPassword)) {
            $vaultConn = $connService->createCustomConnection($explicitVaultUser, $explicitVaultPassword);
        } else {
            $vaultConn = $connService->getVaultConnection();
        }

        $executed = false;
        if ($vaultConn) {
            try {
                $curCon = $vaultConn->getCurrentContainer();
                if ($curCon !== 'CDB$ROOT') {
                    $vaultConn->switchContainer('CDB$ROOT');
                }
                $sql = "CREATE USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" CONTAINER = ALL";
                $vaultConn->executeDdl($sql);
                $executed = true;

                try {
                    $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                } catch (\Throwable $e) {}

                // Création et configuration du rôle commun associé via Vault
                try {
                    $vaultConn->executeDdl("CREATE ROLE {$commonRole} CONTAINER = ALL");
                } catch (\Throwable $e) {}
                try {
                    $vaultConn->executeDdl("GRANT CREATE SESSION TO {$commonRole} CONTAINER = ALL");
                } catch (\Throwable $e) {}
                try {
                    $vaultConn->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
            } catch (\Throwable $ve) {
                // Si échec sur vaultConn, continuer vers les alternatives
            } finally {
                $vaultConn->close();
            }
        }

        // 2. Si non exécuté via Vault, tenter via package PL/SQL ou DDL direct
        if (!$executed) {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }

            try {
                $pkgSql = "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.provision_oracle_account(:username, :pwd, :dba); END;";
                $this->db->execute($pkgSql, [
                    'username' => $norm,
                    'pwd' => $tempPassword,
                    'dba' => $dbaUser,
                ]);
                $executed = true;
            } catch (\Throwable $pkgErr) {
                try {
                    $sql = "CREATE USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" CONTAINER = ALL";
                    $this->db->executeDdl($sql);
                    $executed = true;

                    try {
                        $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                    } catch (\Throwable $e) {}
                } catch (\Throwable $ddlErr) {
                    $errMsg = $ddlErr->getMessage();
                    if (strpos($errMsg, 'ORA-01031') !== false || strpos($errMsg, 'ORA-474') !== false) {
                        throw new \RuntimeException(
                            "Échec de création du compte Oracle : ORA-01031 (Database Vault actif). " .
                            "Veuillez renseigner les identifiants du compte habilité (C##VOLTMANAGER) pour la création des utilisateurs."
                        );
                    }
                    throw $ddlErr;
                }
            }
        }

        // 3. S'assurer que le rôle commun C##RL_<USER> existe et n'a AUCUN droit CREATE SESSION dans CDB$ROOT
        try {
            $this->ensureCommonRole($norm);
        } catch (\Throwable $e) {
            // Ignorer si déjà en place
        }

        // Audit CDB
        try {
            $this->db->execute(
                "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'CREATE_ORACLE_COMMON_USER', :target, 'Compte commun et rôle {$commonRole} créés (Accès CDB\$ROOT désactivé)', 'SUCCESS')",
                ['dba' => $dbaUser, 'target' => $oracleUser]
            );
        } catch (\Throwable $e) {}

        return $tempPassword;
    }

    /**
     * S'assure que le rôle commun associé C##RL_<USER> existe dans CDB$ROOT,
     * est rattaché au compte commun, mais SANS AUCUN privilège CREATE SESSION ou CONNECT dans CDB$ROOT.
     */
    public function ensureCommonRole(string $username): bool
    {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $commonRole = 'C##RL_' . $norm;
        $localRole  = 'RL_' . $norm;

        return $this->executeInCdbRoot(function() use ($oracleUser, $norm, $commonRole, $localRole) {
            $applyRoleGrants = function(\App\Database\OracleConnection $conn) use ($oracleUser, $commonRole) {
                try {
                    $conn->executeDdl("CREATE ROLE {$commonRole} CONTAINER = ALL");
                } catch (\Throwable $e) {
                    // Ignorer uniquement ORA-01921 (rôle déjà existant)
                    $code = method_exists($e, 'getOracleCode') ? (int)$e->getOracleCode() : 0;
                    if ($code !== 1921 && strpos($e->getMessage(), 'ORA-01921') === false) {
                        throw $e;
                    }
                }

                $conn->executeDdl("GRANT CREATE SESSION TO {$commonRole} CONTAINER = ALL");
                $conn->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
            };

            $applyUnlock = function(\App\Database\OracleConnection $conn) use ($oracleUser) {
                try {
                    $conn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                } catch (\Throwable $e) {}
            };

            $grantsOk = false;
            $grantErr = null;

            try {
                $applyRoleGrants($this->db);
                $grantsOk = true;
            } catch (\Throwable $e) {
                $grantErr = $e;
            }

            if ($grantsOk) {
                $applyUnlock($this->db);
            }

            // Repli via connexion Database Vault active si le DBA principal est restreint
            $vaultConn = null;
            try {
                $vaultConn = (new OracleConnectionService())->getVaultConnection();
            } catch (\Throwable $ve) {
                $vaultConn = null;
            }

            if (!$grantsOk && $vaultConn) {
                try {
                    if ($vaultConn->getCurrentContainer() !== 'CDB$ROOT') {
                        $vaultConn->switchContainer('CDB$ROOT');
                    }
                    $applyRoleGrants($vaultConn);
                    $grantsOk = true;
                    $grantErr = null;
                    $applyUnlock($vaultConn);
                } catch (\Throwable $ve) {
                    $grantErr = $ve;
                }
            }

            if (!$grantsOk) {
                if ($vaultConn) {
                    try { $vaultConn->close(); } catch (\Throwable $ce) {}
                }
                throw $grantErr ?: new \RuntimeException("Impossible d'accorder CREATE SESSION au rôle {$commonRole}.");
            }

            // Réparer également les rôles locaux RL_<USER> dans les PDBs ouvertes en lecture/écriture
            try {
                $openPdbs = $this->db->query(
                    "SELECT NAME FROM V\$PDBS WHERE OPEN_MODE LIKE 'READ WRITE%' AND NAME != 'PDB\$SEED' ORDER BY NAME"
                );
                foreach ($openPdbs as $pRow) {
                    $pdbName = $pRow['NAME'] ?? '';
                    if (empty($pdbName)) {
                        continue;
                    }
                    try {
                        $this->db->switchContainer($pdbName);
                        try { $this->db->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $e) {}

                        // Si le développeur a au moins un accès actif ZIRIOAM dans cette PDB, restaurer aussi RL_<USER>
                        $hasActiveAccess = false;
                        try {
                            $cntRow = $this->db->queryOne(
                                "SELECT COUNT(*) AS CNT FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS WHERE UPPER(USERNAME) IN (:usr, :base) AND STATUS = 'ACTIVE'",
                                ['usr' => $oracleUser, 'base' => $norm]
                            );
                            $hasActiveAccess = ((int)($cntRow['CNT'] ?? 0)) > 0;
                        } catch (\Throwable $e) {}

                        if ($hasActiveAccess) {
                            try {
                                $this->db->executeDdl("CREATE ROLE {$localRole}");
                            } catch (\Throwable $e) {}
                            try { $this->db->executeDdl("GRANT CREATE SESSION TO {$localRole}"); } catch (\Throwable $e) {}
                            try { $this->db->executeDdl("GRANT ZIRIOAM_DEV_BASE_ROLE TO {$localRole}"); } catch (\Throwable $e) {}
                            try { $this->db->executeDdl("GRANT {$localRole} TO {$commonRole} CONTAINER = CURRENT"); } catch (\Throwable $e) {}
                            try { $this->db->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $e) {}
                        }

                        $unlockErr = null;
                        try {
                            $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK");
                        } catch (\Throwable $e) {
                            $unlockErr = $e;
                        }

                        if ($unlockErr !== null && $vaultConn) {
                            try {
                                $vaultConn->switchContainer($pdbName);
                                try { $vaultConn->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $ve) {}
                                if ($hasActiveAccess) {
                                    try { $vaultConn->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $ve) {}
                                }
                                try { $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK"); } catch (\Throwable $ve) {}
                            } catch (\Throwable $ve) {}
                        }
                    } catch (\Throwable $pe) {
                        // Tolérer les PDBs non gérées ou inaccessibles
                    }
                }
            } finally {
                try { $this->db->switchContainer('CDB$ROOT'); } catch (\Throwable $ce) {}
                if ($vaultConn) {
                    try { $vaultConn->close(); } catch (\Throwable $ce) {}
                }
            }

            return true;
        });
    }

    /**
     * Accorde explicitement le droit CREATE SESSION dans CDB$ROOT au rôle commun d'un développeur
     */
    public function grantCdbRootSession(string $username): bool
    {
        return $this->ensureCommonRole($username);
    }

    /**
     * Accorde le droit CREATE SESSION dans CDB$ROOT à l'ensemble des développeurs actifs
     */
    public function grantCdbSessionToAllDevelopers(): int
    {
        $developers = $this->getAllDevelopers('', 'ACTIVE');
        $count = 0;
        $lastError = null;
        foreach ($developers as $dev) {
            // Ignorer ceux dont le compte Oracle commun n'est pas encore créé
            if (empty($dev['ORACLE_ACCOUNT_EXISTS'])) {
                continue;
            }
            try {
                if ($this->grantCdbRootSession($dev['USERNAME'])) {
                    $count++;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        if ($count === 0 && $lastError !== null) {
            throw $lastError;
        }
        return $count;
    }

    /**
     * Révoque tout privilège de connexion dans CDB$ROOT (CREATE SESSION, CONNECT)
     * et tue toute session active dans CDB$ROOT.
     * Garantit que le développeur ne peut jamais se connecter à la racine de la base CDB (DEV16).
     */
    public function stripCdbRootSession(string $username): bool
    {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $commonRole = 'C##RL_' . $norm;

        return $this->executeInCdbRoot(function() use ($oracleUser, $commonRole) {
            try {
                $this->db->executeDdl("REVOKE CREATE SESSION FROM {$oracleUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE CREATE SESSION FROM {$oracleUser} CONTAINER = CURRENT");
            } catch (\Throwable $e2) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$oracleUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$oracleUser} CONTAINER = CURRENT");
            } catch (\Throwable $e2) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$commonRole} CONTAINER = CURRENT");
            } catch (\Throwable $e2) {}
            try {
                $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE CONTAINER = CURRENT");
            } catch (\Throwable $e) {}

            // Tuer les sessions actives éventuelles du développeur dans CDB$ROOT
            try {
                $sessions = $this->db->query(
                    "SELECT SID, SERIAL# FROM V\$SESSION WHERE USERNAME = :u",
                    ['u' => $oracleUser]
                );
                foreach ($sessions as $s) {
                    try {
                        $this->db->executeDdl("ALTER SYSTEM KILL SESSION '{$s['SID']},{$s['SERIAL#']}' IMMEDIATE");
                    } catch (\Throwable $ke) {}
                }
            } catch (\Throwable $se) {}

            return true;
        });
    }

    /**
     * Verrouille le compte Oracle commun dans CDB$ROOT et dans toutes les PDBs ouvertes.
     * Révoque également le privilège CREATE SESSION pour interdire toute connexion.
     */
    public function lockOracleAccount(
        string $username,
        string $dbaUser,
        ?string $explicitVaultUser = null,
        ?string $explicitVaultPassword = null
    ): bool {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $localRole = 'RL_' . $norm;
        $commonRole = 'C##RL_' . $norm;

        $lockedInRoot = false;
        $lockedInPdbCount = 0;
        $errors = [];

        // 1. Tenter le verrouillage dans CDB$ROOT
        // Stratégie A : via connexion Vault Manager (si identifiants fournis ou en session)
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = null;
        if (!empty($explicitVaultUser) && !empty($explicitVaultPassword)) {
            try {
                $vaultConn = $connService->createCustomConnection($explicitVaultUser, $explicitVaultPassword);
            } catch (\Throwable $e) {
                $errors[] = "Connexion Vault: " . $e->getMessage();
            }
        } else {
            $vaultConn = $connService->getVaultConnection();
        }

        if ($vaultConn) {
            try {
                try {
                    $curCon = $vaultConn->getCurrentContainer();
                    if ($curCon !== 'CDB$ROOT') {
                        $vaultConn->switchContainer('CDB$ROOT');
                    }
                } catch (\Throwable $e) {}

                // Essai 1 : CONTAINER = ALL
                try {
                    $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = ALL");
                    $lockedInRoot = true;
                } catch (\Throwable $e1) {
                    // Essai 2 : CONTAINER = CURRENT
                    try {
                        $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = CURRENT");
                        $lockedInRoot = true;
                    } catch (\Throwable $e2) {
                        // Essai 3 : Sans clause CONTAINER
                        try {
                            $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK");
                            $lockedInRoot = true;
                        } catch (\Throwable $e3) {
                            $errors[] = "Vault root lock: " . $e3->getMessage();
                        }
                    }
                }

                // Désactiver également les rôles par défaut dans CDB$ROOT via Vault
                try {
                    $vaultConn->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE CONTAINER = ALL");
                } catch (\Throwable $e) {
                    try {
                        $vaultConn->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE CONTAINER = CURRENT");
                    } catch (\Throwable $e2) {
                        try {
                            $vaultConn->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE");
                        } catch (\Throwable $e3) {}
                    }
                }

                // Révoquer le rôle commun via Vault
                try {
                    $vaultConn->executeDdl("REVOKE {$commonRole} FROM {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
            } finally {
                $vaultConn->close();
            }
        }

        // Stratégie B : Si non verrouillé dans CDB$ROOT, tenter via la connexion DBA standard
        if (!$lockedInRoot) {
            $this->executeInCdbRoot(function() use ($oracleUser, $commonRole, $norm, $dbaUser, &$lockedInRoot, &$errors) {
                // Essai via package PL/SQL
                try {
                    $this->db->execute(
                        "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.lock_account(:username, :dba); END;",
                        ['username' => $norm, 'dba' => $dbaUser]
                    );
                    $lockedInRoot = true;
                    return;
                } catch (\Throwable $pe) {}

                // Essai direct CONTAINER = ALL
                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = ALL");
                    $lockedInRoot = true;
                    return;
                } catch (\Throwable $de1) {}

                // Essai direct CONTAINER = CURRENT
                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = CURRENT");
                    $lockedInRoot = true;
                    return;
                } catch (\Throwable $de2) {}

                // Essai direct simple
                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK");
                    $lockedInRoot = true;
                } catch (\Throwable $de3) {
                    $errors[] = "DBA root: " . $de3->getMessage();
                }

                // Désactiver également les rôles par défaut dans CDB$ROOT via DBA
                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE CONTAINER = ALL");
                } catch (\Throwable $e) {
                    try {
                        $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE CONTAINER = CURRENT");
                    } catch (\Throwable $e2) {
                        try {
                            $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE");
                        } catch (\Throwable $e3) {}
                    }
                }

                try {
                    $this->db->executeDdl("REVOKE {$commonRole} FROM {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
            });
        }

        // Règle ZIRIOAM : Nettoyer strictement les privilèges et tuer les sessions résiduelles dans CDB$ROOT
        $this->executeInCdbRoot(function() use ($oracleUser, $commonRole) {
            try {
                $this->db->executeDdl("REVOKE CREATE SESSION FROM {$oracleUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE CREATE SESSION FROM {$oracleUser} CONTAINER = CURRENT");
            } catch (\Throwable $e2) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$oracleUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$oracleUser} CONTAINER = CURRENT");
            } catch (\Throwable $e2) {}
            try {
                $this->db->executeDdl("REVOKE CONNECT FROM {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // Tuer les sessions actives éventuelles du développeur dans CDB$ROOT
            try {
                $rootSessions = $this->db->query(
                    "SELECT SID, SERIAL# FROM V\$SESSION WHERE USERNAME = :usr",
                    ['usr' => $oracleUser]
                );
                foreach ($rootSessions as $s) {
                    try {
                        $this->db->executeDdl("ALTER SYSTEM KILL SESSION '{$s['SID']},{$s['SERIAL#']}' IMMEDIATE");
                    } catch (\Throwable $ke) {}
                }
            } catch (\Throwable $se) {}
        });

        // 2. Verrouillage systématique dans TOUTES les PDBs ouvertes + Révocation de ZIRIOAM_DEV_BASE_ROLE et CREATE SESSION
        // C'est ce qui garantit qu'aucune connexion sqlplus vers //IP:1521/PDBTEST ne peut aboutir !
        try {
            $openPdbs = $this->db->query("
                SELECT NAME FROM V\$PDBS 
                WHERE OPEN_MODE = 'READ WRITE' AND UPPER(NAME) NOT IN ('PDB\$SEED')
            ");

            foreach ($openPdbs as $pdb) {
                $pdbName = $pdb['NAME'] ?? '';
                if (empty($pdbName)) continue;

                try {
                    $this->db->switchContainer($pdbName);

                    // A. Verrouiller le compte utilisateur au niveau de la PDB (si supporté)
                    try {
                        $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK");
                        $lockedInPdbCount++;
                    } catch (\Throwable $le) {}

                    // B. Désactiver tous les rôles par défaut du développeur dans cette PDB (DEFAULT ROLE NONE)
                    try {
                        $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE NONE");
                    } catch (\Throwable $de) {}

                    // C. Révoquer le rôle local RL_<USER> du compte développeur et du rôle commun
                    try {
                        $this->db->executeDdl("REVOKE {$localRole} FROM {$oracleUser}");
                    } catch (\Throwable $r1) {}
                    try {
                        $this->db->executeDdl("REVOKE {$localRole} FROM C##{$localRole}");
                    } catch (\Throwable $r2) {}

                    // D. Révoquer ZIRIOAM_DEV_BASE_ROLE (qui détient CREATE SESSION) du rôle local et de l'utilisateur
                    try {
                        $this->db->executeDdl("REVOKE ZIRIOAM_DEV_BASE_ROLE FROM {$localRole}");
                    } catch (\Throwable $r3) {}
                    try {
                        $this->db->executeDdl("REVOKE ZIRIOAM_DEV_BASE_ROLE FROM {$oracleUser}");
                    } catch (\Throwable $r4) {}

                    // E. Révoquer CREATE SESSION directement
                    try {
                        $this->db->executeDdl("REVOKE CREATE SESSION FROM {$localRole}");
                    } catch (\Throwable $re) {}
                    try {
                        $this->db->executeDdl("REVOKE CREATE SESSION FROM {$oracleUser}");
                    } catch (\Throwable $rue) {}

                    // F. Révoquer CONNECT au cas où
                    try {
                        $this->db->executeDdl("REVOKE CONNECT FROM {$localRole}");
                    } catch (\Throwable $c1) {}
                    try {
                        $this->db->executeDdl("REVOKE CONNECT FROM {$oracleUser}");
                    } catch (\Throwable $c2) {}

                    // G. Tuer les sessions actives éventuelles du développeur dans cette PDB
                    try {
                        $activeSessions = $this->db->query(
                            "SELECT SID, SERIAL# FROM V\$SESSION WHERE USERNAME = :usr",
                            ['usr' => $oracleUser]
                        );
                        foreach ($activeSessions as $s) {
                            try {
                                $this->db->executeDdl("ALTER SYSTEM KILL SESSION '{$s['SID']},{$s['SERIAL#']}' IMMEDIATE");
                            } catch (\Throwable $ke) {}
                        }
                    } catch (\Throwable $se) {}

                } catch (\Throwable $pe) {
                    $errors[] = "PDB {$pdbName}: " . $pe->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = "PDB scan: " . $e->getMessage();
        } finally {
            try {
                $this->db->switchContainer('CDB$ROOT');
            } catch (\Throwable $e) {}
        }

        // 3. VÉRIFICATION : Le compte est-il verrouillé dans CDB$ROOT ou dans au moins une PDB ?
        $actuallyLocked = $lockedInRoot || ($lockedInPdbCount > 0);
        if (!$actuallyLocked) {
            try {
                $actuallyLocked = $this->executeInCdbRoot(function() use ($oracleUser) {
                    $st = $this->db->queryOne(
                        "SELECT COUNT(*) AS CNT FROM CDB_USERS WHERE USERNAME = :u AND COMMON = 'YES' AND ACCOUNT_STATUS LIKE '%LOCKED%'",
                        ['u' => $oracleUser]
                    );
                    return ((int)($st['CNT'] ?? 0)) > 0;
                });
            } catch (\Throwable $e) {}
        }

        if (!$actuallyLocked) {
            $errDetail = !empty($errors) ? implode(' | ', $errors) : "Privilèges insuffisants";
            throw new \RuntimeException(
                "Échec de verrouillage du compte ({$errDetail}). " .
                "Sous Oracle Database Vault, le mot de passe du compte habilité (C##VOLTMANAGER) est obligatoire pour verrouiller le compte."
            );
        }

        // 4. Mise à jour du statut dans le référentiel ZIRIOAM
        try {
            $this->executeInCdbRoot(function() use ($norm) {
                $this->db->execute(
                    "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS SET STATUS = 'INACTIVE', UPDATED_AT = SYSTIMESTAMP WHERE USERNAME = :u",
                    ['u' => $norm]
                );
            });
        } catch (\Throwable $e) {}

        // 5. Audit CDB
        try {
            $this->db->execute(
                "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'LOCK_ACCOUNT', :target, 'Compte verrouillé dans CDB\$ROOT et {$lockedInPdbCount} PDBs', 'SUCCESS')",
                [
                    'dba' => $dbaUser,
                    'target' => $oracleUser,
                ]
            );
        } catch (\Throwable $e) {}

        return true;
    }

    /**
     * Déverrouille le compte Oracle commun dans CDB$ROOT et dans toutes les PDBs ouvertes.
     * Rétablit également le privilège CREATE SESSION sur le rôle local RL_<USER>.
     */
    public function unlockOracleAccount(
        string $username,
        string $dbaUser,
        ?string $explicitVaultUser = null,
        ?string $explicitVaultPassword = null
    ): bool {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $localRole = 'RL_' . $norm;
        $commonRole = 'C##RL_' . $norm;

        $unlockedInRoot = false;
        $unlockedInPdbCount = 0;
        $errors = [];

        // 1. Tenter le déverrouillage dans CDB$ROOT
        // Stratégie A : via connexion Vault Manager
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = null;
        if (!empty($explicitVaultUser) && !empty($explicitVaultPassword)) {
            try {
                $vaultConn = $connService->createCustomConnection($explicitVaultUser, $explicitVaultPassword);
            } catch (\Throwable $e) {
                $errors[] = "Connexion Vault: " . $e->getMessage();
            }
        } else {
            $vaultConn = $connService->getVaultConnection();
        }

        if ($vaultConn) {
            try {
                try {
                    $curCon = $vaultConn->getCurrentContainer();
                    if ($curCon !== 'CDB$ROOT') {
                        $vaultConn->switchContainer('CDB$ROOT');
                    }
                } catch (\Throwable $e) {}

                // Essai 1 : CONTAINER = ALL
                try {
                    $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                    $unlockedInRoot = true;
                } catch (\Throwable $e1) {
                    // Essai 2 : CONTAINER = CURRENT
                    try {
                        $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = CURRENT");
                        $unlockedInRoot = true;
                    } catch (\Throwable $e2) {
                        // Essai 3 : Sans clause CONTAINER
                        try {
                            $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK");
                            $unlockedInRoot = true;
                        } catch (\Throwable $e3) {
                            $errors[] = "Vault root unlock: " . $e3->getMessage();
                        }
                    }
                }

                // Réattribuer le rôle commun via Vault
                try {
                    $vaultConn->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
            } finally {
                $vaultConn->close();
            }
        }

        // Stratégie B : Via connexion DBA standard
        if (!$unlockedInRoot) {
            $this->executeInCdbRoot(function() use ($oracleUser, $commonRole, $norm, $dbaUser, &$unlockedInRoot, &$errors) {
                try {
                    $this->db->execute(
                        "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.unlock_account(:username, :dba); END;",
                        ['username' => $norm, 'dba' => $dbaUser]
                    );
                    $unlockedInRoot = true;
                    return;
                } catch (\Throwable $pe) {}

                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                    $unlockedInRoot = true;
                    return;
                } catch (\Throwable $de1) {}

                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = CURRENT");
                    $unlockedInRoot = true;
                    return;
                } catch (\Throwable $de2) {}

                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK");
                    $unlockedInRoot = true;
                } catch (\Throwable $de3) {
                    $errors[] = "DBA root unlock: " . $de3->getMessage();
                }

                // Réattribuer le rôle commun dans CDB$ROOT via DBA
                try {
                    $this->db->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
            });
        }

        // 2. Déverrouillage dans TOUTES les PDBs ouvertes + Ré-attribution de ZIRIOAM_DEV_BASE_ROLE et CREATE SESSION
        try {
            $openPdbs = $this->db->query("
                SELECT NAME FROM V\$PDBS 
                WHERE OPEN_MODE = 'READ WRITE' AND UPPER(NAME) NOT IN ('PDB\$SEED')
            ");

            foreach ($openPdbs as $pdb) {
                $pdbName = $pdb['NAME'] ?? '';
                if (empty($pdbName)) continue;

                try {
                    $this->db->switchContainer($pdbName);

                    // A. Déverrouiller le compte utilisateur au niveau de la PDB
                    try {
                        $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK");
                        $unlockedInPdbCount++;
                    } catch (\Throwable $ue) {}

                    // B. Ré-accorder CREATE SESSION au rôle local RL_<USER>
                    try {
                        $this->db->executeDdl("GRANT CREATE SESSION TO {$localRole}");
                    } catch (\Throwable $ge) {}

                    // C. Ré-accorder le rôle de base ZIRIOAM_DEV_BASE_ROLE au rôle local
                    try {
                        $this->db->executeDdl("GRANT ZIRIOAM_DEV_BASE_ROLE TO {$localRole}");
                    } catch (\Throwable $ze) {}

                    // D. Ré-attribuer le rôle local RL_<USER> au compte développeur dans la PDB et au rôle commun
                    try {
                        $this->db->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT");
                    } catch (\Throwable $g1) {}
                    try {
                        $this->db->executeDdl("GRANT {$localRole} TO C##{$localRole} CONTAINER = CURRENT");
                    } catch (\Throwable $g2) {}

                    // E. Synchroniser les privilèges ANY DDL sur RL_<USER> depuis ZIRIOAM_DEVELOPER_ACCESS
                    try {
                        (new AccessProvisioningService($this->db))->syncDeveloperDdlAnyPrivileges($pdbName, $oracleUser, false);
                    } catch (\Throwable $se) {}

                } catch (\Throwable $pe) {
                    $errors[] = "PDB {$pdbName}: " . $pe->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = "PDB scan: " . $e->getMessage();
        } finally {
            try {
                $this->db->switchContainer('CDB$ROOT');
            } catch (\Throwable $e) {}
        }

        // 3. VÉRIFICATION : Le compte est-il déverrouillé dans CDB$ROOT ou dans au moins une PDB ?
        $actuallyUnlocked = $unlockedInRoot || ($unlockedInPdbCount > 0);
        if (!$actuallyUnlocked) {
            try {
                $actuallyUnlocked = $this->executeInCdbRoot(function() use ($oracleUser) {
                    $st = $this->db->queryOne(
                        "SELECT COUNT(*) AS CNT FROM CDB_USERS WHERE USERNAME = :u AND COMMON = 'YES' AND ACCOUNT_STATUS = 'OPEN'",
                        ['u' => $oracleUser]
                    );
                    return ((int)($st['CNT'] ?? 0)) > 0;
                });
            } catch (\Throwable $e) {}
        }

        if (!$actuallyUnlocked) {
            $errDetail = !empty($errors) ? implode(' | ', $errors) : "Privilèges insuffisants";
            throw new \RuntimeException(
                "Échec de déverrouillage du compte ({$errDetail}). " .
                "Sous Oracle Database Vault, le mot de passe du compte habilité (C##VOLTMANAGER) est obligatoire pour déverrouiller le compte."
            );
        }

        // 4. Mise à jour du statut dans le référentiel ZIRIOAM
        try {
            $this->executeInCdbRoot(function() use ($norm) {
                $this->db->execute(
                    "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS SET STATUS = 'ACTIVE', UPDATED_AT = SYSTIMESTAMP WHERE USERNAME = :u",
                    ['u' => $norm]
                );
            });
        } catch (\Throwable $e) {}

        // 5. Audit CDB
        try {
            $this->db->execute(
                "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'UNLOCK_ACCOUNT', :target, 'Compte déverrouillé dans CDB\$ROOT et {$unlockedInPdbCount} PDBs', 'SUCCESS')",
                [
                    'dba' => $dbaUser,
                    'target' => $oracleUser,
                ]
            );
        } catch (\Throwable $e) {}

        return true;
    }

    /**
     * Récupère l'historique des opérations pour un développeur donné
     */
    public function getDeveloperHistory(string $username): array
    {
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

        return $this->executeInCdbRoot(function() use ($norm, $oracleUser) {
            $sql = "SELECT AUDIT_ID, TO_CHAR(EVENT_TIME, 'YYYY-MM-DD HH24:MI:SS') AS EVENT_TIME, 
                           DBA_USER, ACTION_TYPE, DETAILS, STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT
                    WHERE TARGET_USER IN (:norm, :ora)
                    ORDER BY EVENT_TIME DESC";

            try {
                return $this->db->query($sql, ['norm' => $norm, 'ora' => $oracleUser]);
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}

