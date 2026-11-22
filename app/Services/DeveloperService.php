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
                           (SELECT ACCOUNT_STATUS FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES' AND ROWNUM = 1) AS ORACLE_ACCOUNT_STATUS
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
                           (SELECT ACCOUNT_STATUS FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES' AND ROWNUM = 1) AS ORACLE_ACCOUNT_STATUS
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
     * Règle de sécurité : CREATE USER C##... IDENTIFIED BY ... PASSWORD EXPIRE CONTAINER = ALL;
     * AUCUN PRIVILÈGE CREATE SESSION N'EST DONNÉ À CE NIVEAU !
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
                $sql = "CREATE USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
                $vaultConn->executeDdl($sql);
                $executed = true;

                // Création et configuration du rôle commun associé via Vault
                try {
                    $vaultConn->executeDdl("CREATE ROLE {$commonRole} CONTAINER = ALL");
                } catch (\Throwable $e) {}
                try {
                    $vaultConn->executeDdl("GRANT CONNECT TO {$commonRole} CONTAINER = ALL");
                } catch (\Throwable $e) {}
                try {
                    $vaultConn->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
                } catch (\Throwable $e) {}
                try {
                    $vaultConn->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE ALL CONTAINER = ALL");
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
                    $sql = "CREATE USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
                    $this->db->executeDdl($sql);
                    $executed = true;
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

        // 3. S'assurer que le rôle commun C##RL_<USER> existe et dispose de CONNECT
        try {
            $this->ensureCommonRole($norm);
        } catch (\Throwable $e) {
            // Ignorer si déjà en place
        }

        // 4. Positionner automatiquement les quotas de stockage illimités et UNLIMITED TABLESPACE dans toutes les PDBs ouvertes
        try {
            $openPdbs = $this->db->query("
                SELECT NAME FROM V\$PDBS 
                WHERE OPEN_MODE = 'READ WRITE' AND UPPER(NAME) NOT IN ('PDB\$SEED')
            ");
            $accessService = new AccessProvisioningService($this->db);
            foreach ($openPdbs as $pdb) {
                $pdbName = $pdb['NAME'] ?? '';
                if (!empty($pdbName)) {
                    try {
                        $this->db->switchContainer($pdbName);
                        $accessService->grantTablespaceQuotaForSchema($oracleUser, '', 'RL_' . $norm);
                    } catch (\Throwable $pe) {}
                }
            }
        } catch (\Throwable $e) {
        } finally {
            try {
                $this->db->switchContainer('CDB$ROOT');
            } catch (\Throwable $e) {}
        }

        // Audit CDB
        try {
            $this->db->execute(
                "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'CREATE_ORACLE_COMMON_USER', :target, 'Compte commun et rôle {$commonRole} créés', 'SUCCESS')",
                ['dba' => $dbaUser, 'target' => $oracleUser]
            );
        } catch (\Throwable $e) {}

        return $tempPassword;
    }

    /**
     * S'assure que le rôle commun associé C##RL_<USER> existe dans CDB$ROOT,
     * dispose du rôle CONNECT et est rattaché au compte commun (Règles 1 & 2).
     */
    public function ensureCommonRole(string $username): bool
    {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;
        $commonRole = 'C##RL_' . $norm;

        return $this->executeInCdbRoot(function() use ($oracleUser, $commonRole) {
            // 1. Créer le rôle commun si inexistant
            try {
                $this->db->executeDdl("CREATE ROLE {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {
                // Existe déjà (ORA-01921)
            }

            // 2. Accorder le privilège CONNECT au rôle commun
            try {
                $this->db->executeDdl("GRANT CONNECT TO {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 3. Attribuer le rôle commun au compte utilisateur
            try {
                $this->db->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 4. Définir comme rôle par défaut
            try {
                $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE ALL CONTAINER = ALL");
            } catch (\Throwable $e) {}

            return true;
        });
    }

    /**
     * Verrouille le compte Oracle commun
     */
    public function lockOracleAccount(string $username, string $dbaUser): bool
    {
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

        // Tenter via connexion Vault si disponible
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = $connService->getVaultConnection();
        if ($vaultConn) {
            try {
                $curCon = $vaultConn->getCurrentContainer();
                if ($curCon !== 'CDB$ROOT') {
                    $vaultConn->switchContainer('CDB$ROOT');
                }
                $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = ALL");
                $vaultConn->close();
                return true;
            } catch (\Throwable $ve) {
                $vaultConn->close();
            }
        }

        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        try {
            $this->db->execute(
                "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.lock_account(:username, :dba); END;",
                ['username' => $norm, 'dba' => $dbaUser]
            );
        } catch (\Throwable $e) {
            $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT LOCK CONTAINER = ALL");
        }
        return true;
    }

    /**
     * Déverrouille le compte Oracle commun
     */
    public function unlockOracleAccount(string $username, string $dbaUser): bool
    {
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

        // Tenter via connexion Vault si disponible
        $connService = new \App\Services\OracleConnectionService();
        $vaultConn = $connService->getVaultConnection();
        if ($vaultConn) {
            try {
                $curCon = $vaultConn->getCurrentContainer();
                if ($curCon !== 'CDB$ROOT') {
                    $vaultConn->switchContainer('CDB$ROOT');
                }
                $vaultConn->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
                $vaultConn->close();
                return true;
            } catch (\Throwable $ve) {
                $vaultConn->close();
            }
        }

        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        try {
            $this->db->execute(
                "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.unlock_account(:username, :dba); END;",
                ['username' => $norm, 'dba' => $dbaUser]
            );
        } catch (\Throwable $e) {
            $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK CONTAINER = ALL");
        }
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

