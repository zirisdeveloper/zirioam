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
     * Crée un rôle fonctionnel Oracle dans la PDB et synchronise automatiquement les privilèges d'objets
     */
    public function createRole(
        string $pdbName,
        string $schema,
        string $profileType,
        string $description,
        string $dbaUser,
        ?string $customRoleName = null
    ): int {
        $this->db->switchContainer($pdbName);

        $schema = strtoupper(trim($schema));
        $profileType = strtoupper(trim($profileType));

        // Détermination du nom de rôle Oracle (nom personnalisé ou format standard R_<SCHEMA>_<TYPE>)
        if (!empty($customRoleName)) {
            $custom = strtoupper(trim($customRoleName));
            if (!zirioam_is_valid_oracle_identifier($custom)) {
                throw new \InvalidArgumentException("Le nom de rôle '{$custom}' n'est pas un identifiant Oracle valide.");
            }
            $roleName = $custom;
        } else {
            $normType = 'READ';
            if ($profileType === 'APP_WRITE') {
                $normType = 'WRITE';
            } elseif ($profileType === 'APP_READ_WRITE') {
                $normType = 'RW';
            } elseif ($profileType === 'APP_EXECUTE') {
                $normType = 'EXEC';
            }
            $roleName = "R_{$schema}_{$normType}";
        }

        // 1. Créer le rôle Oracle physique si inexistant
        try {
            $this->db->executeDdl("CREATE ROLE {$roleName}");
        } catch (\Throwable $e) {
            // Rôle physique existe déjà (ORA-01921)
        }

        // 2. Synchroniser les privilèges sur toutes les tables/vues existantes du schéma cible
        $this->syncSchemaPrivilegesToRole($schema, $roleName, $profileType);

        // 3. Insérer ou mettre à jour dans la table ZIRIOAM_ACCESS_ROLES
        $mergeSql = "MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES dest
                     USING (SELECT :rname AS rname FROM DUAL) src
                     ON (dest.ROLE_NAME = src.rname)
                     WHEN MATCHED THEN
                         UPDATE SET dest.TARGET_SCHEMA = :schema, dest.PROFILE_TYPE = :ptype, dest.DESCRIPTION = :descr, dest.IS_ACTIVE = 'Y'
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
     * Accorde automatiquement les privilèges d'accès sur toutes les tables, vues et séquences
     * du schéma cible au rôle spécifié (Résolution de ORA-00942).
     */
    public function syncSchemaPrivilegesToRole(string $schema, string $roleName, string $profileType = 'APP_READ_WRITE'): array
    {
        $schema = strtoupper(trim($schema));
        $roleName = strtoupper(trim($roleName));
        $profileType = strtoupper(trim($profileType));

        $granted = [];
        $errors = [];

        $canSelect = in_array($profileType, ['APP_READ', 'READ', 'APP_READ_WRITE', 'RW', 'APP_ALL', 'ALL']);
        $canDml = in_array($profileType, ['APP_WRITE', 'WRITE', 'APP_READ_WRITE', 'RW', 'APP_ALL', 'ALL']);
        $canExecute = in_array($profileType, ['APP_EXECUTE', 'EXEC', 'APP_ALL', 'ALL']);

        // 1. Tables du schéma
        $tables = [];
        try {
            $tables = $this->db->query("SELECT TABLE_NAME FROM DBA_TABLES WHERE OWNER = :s ORDER BY TABLE_NAME", ['s' => $schema]);
        } catch (\Throwable $e) {
            try {
                $tables = $this->db->query("SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = :s ORDER BY TABLE_NAME", ['s' => $schema]);
            } catch (\Throwable $e2) {}
        }

        foreach ($tables as $t) {
            $tbl = $t['TABLE_NAME'];
            if ($canSelect && $canDml) {
                $sql = "GRANT SELECT, INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$tbl}\" TO {$roleName}";
            } elseif ($canSelect) {
                $sql = "GRANT SELECT ON \"{$schema}\".\"{$tbl}\" TO {$roleName}";
            } elseif ($canDml) {
                $sql = "GRANT INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$tbl}\" TO {$roleName}";
            } else {
                continue;
            }

            try {
                $this->db->executeDdl($sql);
                $granted[] = "TABLE {$tbl}";
            } catch (\Throwable $e) {
                $errors[] = "TABLE {$tbl}: " . $e->getMessage();
            }
        }

        // 2. Vues du schéma
        $views = [];
        try {
            $views = $this->db->query("SELECT VIEW_NAME FROM DBA_VIEWS WHERE OWNER = :s ORDER BY VIEW_NAME", ['s' => $schema]);
        } catch (\Throwable $e) {
            try {
                $views = $this->db->query("SELECT VIEW_NAME FROM ALL_VIEWS WHERE OWNER = :s ORDER BY VIEW_NAME", ['s' => $schema]);
            } catch (\Throwable $e2) {}
        }

        foreach ($views as $v) {
            $vw = $v['VIEW_NAME'];
            if ($canSelect) {
                $sql = "GRANT SELECT ON \"{$schema}\".\"{$vw}\" TO {$roleName}";
                try {
                    $this->db->executeDdl($sql);
                    $granted[] = "VIEW {$vw}";
                } catch (\Throwable $e) {
                    $errors[] = "VIEW {$vw}: " . $e->getMessage();
                }
            }
        }

        // 3. Séquences du schéma
        $sequences = [];
        try {
            $sequences = $this->db->query("SELECT SEQUENCE_NAME FROM DBA_SEQUENCES WHERE SEQUENCE_OWNER = :s ORDER BY SEQUENCE_NAME", ['s' => $schema]);
        } catch (\Throwable $e) {
            try {
                $sequences = $this->db->query("SELECT SEQUENCE_NAME FROM ALL_SEQUENCES WHERE SEQUENCE_OWNER = :s ORDER BY SEQUENCE_NAME", ['s' => $schema]);
            } catch (\Throwable $e2) {}
        }

        foreach ($sequences as $sq) {
            $seq = $sq['SEQUENCE_NAME'];
            if ($canSelect || $canDml) {
                $sql = "GRANT SELECT ON \"{$schema}\".\"{$seq}\" TO {$roleName}";
                try {
                    $this->db->executeDdl($sql);
                    $granted[] = "SEQUENCE {$seq}";
                } catch (\Throwable $e) {
                    $errors[] = "SEQUENCE {$seq}: " . $e->getMessage();
                }
            }
        }

        return ['granted' => $granted, 'errors' => $errors];
    }

    /**
     * S'assure que le rôle commun C##RL_<USER> existe dans CDB$ROOT et a CONNECT (Règles 1 & 2)
     */
    public function ensureCommonRoleInCdbRoot(string $baseUser): void
    {
        $base = strtoupper(trim($baseUser));
        if (str_starts_with($base, 'C##')) {
            $base = substr($base, 3);
        }
        $commonUser = 'C##' . $base;
        $commonRole = 'C##RL_' . $base;

        $previousContainer = $this->db->getCurrentContainer();
        try {
            if ($previousContainer !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }

            // 1. Créer le rôle commun si inexistant
            try {
                $this->db->executeDdl("CREATE ROLE {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {
                // Existe déjà (ORA-01921)
            }

            // 2. Accorder CONNECT au rôle commun
            try {
                $this->db->executeDdl("GRANT CONNECT TO {$commonRole} CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 3. Attribuer le rôle commun au compte utilisateur
            try {
                $this->db->executeDdl("GRANT {$commonRole} TO {$commonUser} CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 4. Définir le rôle par défaut
            try {
                $this->db->executeDdl("ALTER USER {$commonUser} DEFAULT ROLE ALL CONTAINER = ALL");
            } catch (\Throwable $e) {}
        } finally {
            if ($previousContainer && $previousContainer !== 'CDB$ROOT') {
                $this->db->switchContainer($previousContainer);
            }
        }
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
     * Attribue ou met à jour les accès d'un développeur (Architecture des Rôles : Commun + Locaux PDB)
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
        $normUser = strtoupper(trim($username));
        $baseUser = str_starts_with($normUser, 'C##') ? substr($normUser, 3) : $normUser;
        $oracleUser = 'C##' . $baseUser;
        $commonRole = 'C##RL_' . $baseUser;
        $localRole = 'RL_' . $baseUser;
        $schema = strtoupper(trim($schema));

        // RÈGLE 1 & 2 : S'assurer que le rôle commun C##RL_<USER> existe dans CDB$ROOT avec CONNECT
        $this->ensureCommonRoleInCdbRoot($baseUser);

        // Basculer sur la PDB cible
        $this->db->switchContainer($pdbName);

        // RÈGLE 3 :
        // 1. Vérifier si le rôle local RL_<USER> existe au niveau de PDB1 sinon on le crée
        try {
            $this->db->executeDdl("CREATE ROLE {$localRole}");
        } catch (\Throwable $e) {
            // Existe déjà (ORA-01921)
        }

        // 2. Attribuer le privilège CREATE SESSION à ce rôle local (CONTAINER = CURRENT)
        try {
            $this->db->executeDdl("GRANT CREATE SESSION TO {$localRole}");
            $this->db->executeDdl("GRANT ZIRIOAM_DEV_BASE_ROLE TO {$localRole}");
        } catch (\Throwable $e) {}

        // 3. Attribuer les privilèges du rôle fonctionnel sélectionné au rôle local
        if ($roleId) {
            $roleRow = $this->db->queryOne("SELECT ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = :rid", ['rid' => $roleId]);
            if ($roleRow && !empty($roleRow['ROLE_NAME'])) {
                $roleName = $roleRow['ROLE_NAME'];
                try {
                    $this->db->executeDdl("GRANT {$roleName} TO {$localRole}");
                } catch (\Throwable $e) {}

                // Synchroniser automatiquement les tables et vues du schéma vers ce rôle
                $this->syncSchemaPrivilegesToRole($schema, $roleName, $roleRow['PROFILE_TYPE'] ?? 'APP_READ_WRITE');
            }
        }

        // 4. Ensuite accorder le rôle local au rôle commun : grant RL_USER1 to C##RL_USER1 container=current
        try {
            $this->db->executeDdl("GRANT {$localRole} TO {$commonRole} CONTAINER = CURRENT");
        } catch (\Throwable $e) {
            // Tolérance si Oracle restreint l'attribution d'un rôle local à un rôle commun (ORA-65033/ORA-65030)
        }

        // 5. Attribuer également le rôle local directement au compte développeur dans la PDB
        try {
            $this->db->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT");
        } catch (\Throwable $e) {}

        try {
            $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE ALL");
        } catch (\Throwable $e) {}

        // 6. Mettre à jour la matrice ZIRIOAM_DEVELOPER_ACCESS
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

        // 7. Audit
        try {
            $auditSql = "INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG 
                         (EVENT_TIME, DBA_USER, OPERATION, TARGET_DEVELOPER, TARGET_SCHEMA, RESULT, DETAILS)
                         VALUES (SYSTIMESTAMP, :dba, 'SET_ACCESS_MATRIX', :u, :s, 'SUCCESS', :details)";
            $this->db->execute($auditSql, [
                'dba' => $dbaUser,
                'u' => $oracleUser,
                's' => $schema,
                'details' => "Role: " . ($roleId ?: 'Aucun') . " (Local: {$localRole}) | DDL: C={$canCreate} A={$canAlter} D={$canDrop}",
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
        $normUser = strtoupper(trim($user));
        $baseUser = str_starts_with($normUser, 'C##') ? substr($normUser, 3) : $normUser;
        $localRole = 'RL_' . $baseUser;

        // Révoquer le rôle physique du rôle local et du compte utilisateur
        if (!empty($row['ROLE_NAME'])) {
            try {
                $this->db->executeDdl("REVOKE {$row['ROLE_NAME']} FROM {$localRole}");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE {$row['ROLE_NAME']} FROM {$user}");
            } catch (\Throwable $e) {}
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

