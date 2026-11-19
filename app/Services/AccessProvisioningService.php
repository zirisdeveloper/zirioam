<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service de Gestion des Rôles et d'Attribution des Privilèges (Séparation DDL / DML)
 */
class AccessProvisioningService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Récupère la liste des rôles ZIRIOAM dans une PDB donnée
     */
    public function getRoles(string $pdbName, string $targetSchema = ''): array
    {
        $this->db->switchContainer($pdbName);

        $sql = "SELECT r.ROLE_ID, r.ROLE_NAME, r.TARGET_SCHEMA, r.PROFILE_TYPE, r.DESCRIPTION, r.IS_ACTIVE,
                       TO_CHAR(r.CREATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                       (SELECT COUNT(*) FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da WHERE da.ROLE_ID = r.ROLE_ID AND da.STATUS = 'ACTIVE') AS MEMBER_COUNT
                FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES r
                WHERE 1=1";

        $binds = [];
        if (!empty($targetSchema)) {
            $sql .= " AND r.TARGET_SCHEMA = :schema";
            $binds['schema'] = strtoupper(trim($targetSchema));
        }

        $sql .= " ORDER BY r.TARGET_SCHEMA, r.ROLE_NAME";

        try {
            return $this->db->query($sql, $binds);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Crée un rôle fonctionnel Oracle dans la PDB
     */
    public function createRole(string $pdbName, string $schema, string $profileType, string $description, string $dbaUser): int
    {
        $this->db->switchContainer($pdbName);

        $schema = strtoupper(trim($schema));
        $profileType = strtoupper(trim($profileType));

        // Appel du package PL/SQL ZIRIOAM_OWNER.PKG_ZIRIOAM_PDB_ACCESS
        $sql = "DECLARE
                    v_id NUMBER;
                BEGIN
                    v_id := ZIRIOAM_OWNER.PKG_ZIRIOAM_PDB_ACCESS.create_schema_role(
                        p_target_schema => :schema,
                        p_profile_type  => :ptype,
                        p_description   => :descr,
                        p_created_by    => :dba
                    );
                    :out_id := v_id;
                END;";

        // Insertion directe contrôlée
        $normType = 'READ';
        if ($profileType === 'APP_WRITE') {
            $normType = 'WRITE';
        } elseif ($profileType === 'APP_READ_WRITE') {
            $normType = 'RW';
        } elseif ($profileType === 'APP_EXECUTE') {
            $normType = 'EXEC';
        }
        $roleName = "R_{$schema}_{$normType}";

        // Créer le rôle Oracle physique si inexistant
        try {
            $this->db->executeDdl("CREATE ROLE {$roleName}");
        } catch (\Throwable $e) {
            // Rôle physique existe déjà
        }

        // Insérer dans la table ZIRIOAM_ACCESS_ROLES
        $mergeSql = "MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES dest
                     USING (SELECT :rname AS rname FROM DUAL) src
                     ON (dest.ROLE_NAME = src.rname)
                     WHEN MATCHED THEN
                         UPDATE SET dest.TARGET_SCHEMA = :schema, dest.PROFILE_TYPE = :ptype, dest.DESCRIPTION = :descr
                     WHEN NOT MATCHED THEN
                         INSERT (ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE, DESCRIPTION, IS_ACTIVE, CREATED_BY, CREATED_AT)
                         VALUES (:rname, :schema, :ptype, :descr, 'Y', :dba, SYSTIMESTAMP)";

        $this->db->execute($mergeSql, [
            'rname' => $roleName,
            'schema' => $schema,
            'ptype' => $profileType,
            'descr' => $description,
            'dba' => $dbaUser,
        ]);

        $row = $this->db->queryOne("SELECT ROLE_ID FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_NAME = :rname", ['rname' => $roleName]);
        return (int)($row['ROLE_ID'] ?? 0);
    }

    /**
     * Récupère la matrice des accès d'une PDB
     */
    public function getAccessMatrix(string $pdbName, string $schema = '', string $developer = ''): array
    {
        $this->db->switchContainer($pdbName);

        $sql = "SELECT da.ACCESS_ID, da.USERNAME, da.TARGET_SCHEMA, da.ROLE_ID,
                       ar.ROLE_NAME, ar.PROFILE_TYPE,
                       da.CAN_DDL_CREATE, da.CAN_DDL_ALTER, da.CAN_DDL_DROP,
                       da.STATUS, da.GRANTED_BY,
                       TO_CHAR(da.GRANTED_AT, 'YYYY-MM-DD HH24:MI:SS') AS GRANTED_AT,
                       TO_CHAR(da.UPDATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT
                FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                LEFT JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                WHERE 1=1";

        $binds = [];
        if (!empty($schema)) {
            $sql .= " AND da.TARGET_SCHEMA = :schema";
            $binds['schema'] = strtoupper(trim($schema));
        }

        if (!empty($developer)) {
            $sql .= " AND da.USERNAME = :dev";
            $binds['dev'] = strtoupper(trim($developer));
        }

        $sql .= " ORDER BY da.USERNAME, da.TARGET_SCHEMA";

        try {
            return $this->db->query($sql, $binds);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Attribue ou met à jour les accès d'un développeur (Séparation stricte DDL et DML)
     */
    public function setDeveloperAccess(
        string $pdbName,
        string $username,
        string $schema,
        ?int $roleId,
        int $canCreate,
        int $canAlter,
        int $canDrop,
        string $dbaUser
    ): bool {
        $this->db->switchContainer($pdbName);

        $normUser = strtoupper(trim($username));
        $oracleUser = str_starts_with($normUser, 'C##') ? $normUser : 'C##' . $normUser;
        $schema = strtoupper(trim($schema));

        // 1. Accorder le privilège CREATE SESSION et le rôle de base LOCALEMENT dans la PDB
        try {
            $this->db->executeDdl("GRANT CREATE SESSION TO {$oracleUser}");
            $this->db->executeDdl("GRANT ZIRIOAM_DEV_BASE_ROLE TO {$oracleUser}");
        } catch (\Throwable $e) {
            // Ignorer si déjà accordé
        }

        // 2. Si un rôle DML est sélectionné, l'accorder physiquement dans Oracle
        if ($roleId) {
            $roleRow = $this->db->queryOne("SELECT ROLE_NAME FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = :rid", ['rid' => $roleId]);
            if ($roleRow && !empty($roleRow['ROLE_NAME'])) {
                $roleName = $roleRow['ROLE_NAME'];
                try {
                    $this->db->executeDdl("GRANT {$roleName} TO {$oracleUser}");
                } catch (\Throwable $e) {
                    // Ignorer si déjà accordé
                }
            }
        }

        // 3. Mettre à jour la matrice ZIRIOAM_DEVELOPER_ACCESS
        $mergeSql = "MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS dest
                     USING (SELECT :u AS u, :s AS s FROM DUAL) src
                     ON (dest.USERNAME = src.u AND dest.TARGET_SCHEMA = src.s)
                     WHEN MATCHED THEN
                         UPDATE SET dest.ROLE_ID = :rid,
                                    dest.CAN_DDL_CREATE = :cc,
                                    dest.CAN_DDL_ALTER = :ca,
                                    dest.CAN_DDL_DROP = :cd,
                                    dest.STATUS = 'ACTIVE',
                                    dest.UPDATED_AT = SYSTIMESTAMP
                     WHEN NOT MATCHED THEN
                         INSERT (USERNAME, TARGET_SCHEMA, ROLE_ID, CAN_DDL_CREATE, CAN_DDL_ALTER, CAN_DDL_DROP, STATUS, GRANTED_BY, GRANTED_AT, UPDATED_AT)
                         VALUES (:u, :s, :rid, :cc, :ca, :cd, 'ACTIVE', :dba, SYSTIMESTAMP, SYSTIMESTAMP)";

        $this->db->execute($mergeSql, [
            'u' => $oracleUser,
            's' => $schema,
            'rid' => $roleId,
            'cc' => $canCreate ? 1 : 0,
            'ca' => $canAlter ? 1 : 0,
            'cd' => $canDrop ? 1 : 0,
            'dba' => $dbaUser,
        ]);

        // 4. Audit
        try {
            $auditSql = "INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG 
                         (EVENT_TIME, DBA_USER, OPERATION, TARGET_DEVELOPER, TARGET_SCHEMA, RESULT, DETAILS)
                         VALUES (SYSTIMESTAMP, :dba, 'SET_ACCESS_MATRIX', :u, :s, 'SUCCESS', :details)";
            $this->db->execute($auditSql, [
                'dba' => $dbaUser,
                'u' => $oracleUser,
                's' => $schema,
                'details' => "Role: " . ($roleId ?: 'Aucun') . " | DDL: C={$canCreate} A={$canAlter} D={$canDrop}",
            ]);
        } catch (\Throwable $e) {
            // Ignorer
        }

        return true;
    }

    /**
     * Révoque l'accès d'un développeur sur un schéma dans la PDB
     */
    public function revokeDeveloperAccess(string $pdbName, int $accessId, string $dbaUser): bool
    {
        $this->db->switchContainer($pdbName);

        $row = $this->db->queryOne("SELECT da.USERNAME, da.TARGET_SCHEMA, ar.ROLE_NAME 
                                    FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                                    LEFT JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                                    WHERE da.ACCESS_ID = :id", ['id' => $accessId]);

        if (!$row) return false;

        $user = $row['USERNAME'];
        $schema = $row['TARGET_SCHEMA'];

        // Révoquer le rôle physique si accordé
        if (!empty($row['ROLE_NAME'])) {
            try {
                $this->db->executeDdl("REVOKE {$row['ROLE_NAME']} FROM {$user}");
            } catch (\Throwable $e) {
                // Déjà révoqué
            }
        }

        // Mettre à jour ou supprimer de la matrice
        $this->db->execute("DELETE FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS WHERE ACCESS_ID = :id", ['id' => $accessId]);

        // Audit
        try {
            $this->db->execute(
                "INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG (EVENT_TIME, DBA_USER, OPERATION, TARGET_DEVELOPER, TARGET_SCHEMA, RESULT, DETAILS) " .
                "VALUES (SYSTIMESTAMP, :dba, 'REVOKE_ACCESS', :u, :s, 'SUCCESS', 'Accès révoqué')",
                ['dba' => $dbaUser, 'u' => $user, 's' => $schema]
            );
        } catch (\Throwable $e) {
            // Ignorer
        }

        return true;
    }
}

