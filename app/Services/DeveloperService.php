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
     * Récupère la liste de tous les développeurs avec filtres de recherche
     */
    public function getAllDevelopers(string $search = '', string $status = ''): array
    {
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
    }

    /**
     * Recherche un développeur par son identifiant
     */
    public function getDeveloperById(int $id): ?array
    {
        $sql = "SELECT d.DEVELOPER_ID, d.USERNAME, d.ORACLE_USER, d.FULL_NAME, d.EMAIL, d.STATUS,
                       TO_CHAR(d.CREATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                       TO_CHAR(d.UPDATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT,
                       d.CREATED_BY,
                       (SELECT COUNT(*) FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES') AS ORACLE_ACCOUNT_EXISTS,
                       (SELECT ACCOUNT_STATUS FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES' AND ROWNUM = 1) AS ORACLE_ACCOUNT_STATUS
                FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS d
                WHERE d.DEVELOPER_ID = :id";

        return $this->db->queryOne($sql, ['id' => $id]);
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

        $sql = "SELECT DEVELOPER_ID, USERNAME, ORACLE_USER, FULL_NAME, EMAIL, STATUS
                FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS
                WHERE USERNAME = :username";

        return $this->db->queryOne($sql, ['username' => $norm]);
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

        $sql = "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                SET FULL_NAME = :full_name, EMAIL = :email, STATUS = :status, UPDATED_AT = SYSTIMESTAMP
                WHERE DEVELOPER_ID = :id";

        return $this->db->execute($sql, [
            'full_name' => $fullName,
            'email' => $email,
            'status' => $status,
            'id' => $id,
        ]);
    }

    /**
     * Active ou désactive l'identité (distinct du verrouillage Oracle)
     */
    public function toggleStatus(int $id, string $newStatus, string $dbaUser): bool
    {
        $status = in_array(strtoupper($newStatus), ['ACTIVE', 'INACTIVE']) ? strtoupper($newStatus) : 'ACTIVE';
        $sql = "UPDATE C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                SET STATUS = :status, UPDATED_AT = SYSTIMESTAMP 
                WHERE DEVELOPER_ID = :id";

        return $this->db->execute($sql, ['status' => $status, 'id' => $id]);
    }

    /**
     * Provisionne le compte Oracle commun dans la CDB
     * Règle de sécurité : CREATE USER C##... IDENTIFIED BY ... PASSWORD EXPIRE CONTAINER = ALL;
     * AUCUN PRIVILÈGE CREATE SESSION N'EST DONNÉ À CE NIVEAU !
     */
    public function provisionOracleAccount(string $username, string $dbaUser, ?string $customPassword = null): string
    {
        $norm = strtoupper(trim($username));
        if (str_starts_with($norm, 'C##')) {
            $norm = substr($norm, 3);
        }
        $oracleUser = 'C##' . $norm;

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

        // Exécution dans CDB$ROOT
        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        $executedViaPackage = false;
        try {
            $pkgSql = "BEGIN C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS.provision_oracle_account(:username, :pwd, :dba); END;";
            $this->db->execute($pkgSql, [
                'username' => $norm,
                'pwd' => $tempPassword,
                'dba' => $dbaUser,
            ]);
            $executedViaPackage = true;
        } catch (\Throwable $pkgErr) {
            $sql = "CREATE USER {$oracleUser} IDENTIFIED BY \"{$tempPassword}\" PASSWORD EXPIRE CONTAINER = ALL";
            $this->db->executeDdl($sql);
        }

        if (!$executedViaPackage) {
            // Audit CDB
            $this->db->execute(
                "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'CREATE_ORACLE_COMMON_USER', :target, 'Compte commun créé sans droit de session', 'SUCCESS')",
                ['dba' => $dbaUser, 'target' => $oracleUser]
            );
        }

        return $tempPassword;
    }

    /**
     * Verrouille le compte Oracle commun
     */
    public function lockOracleAccount(string $username, string $dbaUser): bool
    {
        $norm = strtoupper(trim($username));
        $oracleUser = str_starts_with($norm, 'C##') ? $norm : 'C##' . $norm;

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
    }
}

