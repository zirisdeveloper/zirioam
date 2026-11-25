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
            } elseif ($profileType === 'APP_ALL') {
                $normType = 'ALL';
            }
            $roleName = "RL_{$schema}_{$normType}";
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
     * Supprime un rôle applicatif Oracle de la PDB et du catalogue ZIRIOAM
     */
    public function deleteRole(string $pdbName, int $roleId, string $dbaUser): bool
    {
        $this->db->switchContainer($pdbName);

        $roleRow = $this->db->queryOne(
            "SELECT ROLE_ID, ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = :rid",
            ['rid' => $roleId]
        );

        if (!$roleRow) {
            throw new \RuntimeException("Rôle applicatif introuvable dans la PDB {$pdbName}.");
        }

        $roleName = $roleRow['ROLE_NAME'];
        $schema = $roleRow['TARGET_SCHEMA'];
        $isAllProfile = in_array(strtoupper(trim((string)($roleRow['PROFILE_TYPE'] ?? ''))), ['APP_ALL', 'ALL'], true);

        // 1. Révoquer le rôle physique des rôles locaux des développeurs associés
        $devs = $this->db->query(
            "SELECT USERNAME FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS WHERE ROLE_ID = :rid",
            ['rid' => $roleId]
        );

        foreach ($devs as $d) {
            $user = $d['USERNAME'];
            $normUser = strtoupper(trim($user));
            $baseUser = str_starts_with($normUser, 'C##') ? substr($normUser, 3) : $normUser;
            $localRole = 'RL_' . $baseUser;

            try {
                $this->db->executeDdl("REVOKE {$roleName} FROM {$localRole}");
            } catch (\Throwable $e) {}
            try {
                $this->db->executeDdl("REVOKE {$roleName} FROM {$user}");
            } catch (\Throwable $e) {}

            if ($isAllProfile) {
                $this->revokeAllSchemaObjectsFromUser($schema, $normUser);
            }
        }

        // 2. Dissocier de la table ZIRIOAM_DEVELOPER_ACCESS
        try {
            $this->db->execute(
                "UPDATE ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS SET ROLE_ID = NULL, UPDATED_AT = SYSTIMESTAMP WHERE ROLE_ID = :rid",
                ['rid' => $roleId]
            );
        } catch (\Throwable $e) {}

        // 3. Supprimer le rôle physique dans Oracle
        try {
            $this->db->executeDdl("DROP ROLE {$roleName}");
        } catch (\Throwable $e) {
            // Ignorer si déjà supprimé dans Oracle
        }

        // 4. Supprimer l'enregistrement dans ZIRIOAM_ACCESS_ROLES
        $this->db->execute(
            "DELETE FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = :rid",
            ['rid' => $roleId]
        );

        // 5. Audit
        try {
            $auditSql = "INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG 
                         (EVENT_TIME, DBA_USER, OPERATION, TARGET_SCHEMA, TARGET_ROLE, RESULT, DETAILS)
                         VALUES (SYSTIMESTAMP, :dba, 'DELETE_ROLE', :s, :rname, 'SUCCESS', 'Rôle applicatif supprimé')";
            $this->db->execute($auditSql, [
                'dba' => $dbaUser,
                's' => $schema,
                'rname' => $roleName,
            ]);
        } catch (\Throwable $e) {}

        return true;
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
        $isAll = in_array($profileType, ['APP_ALL', 'ALL']);

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
            if ($isAll) {
                // Note Oracle : REFERENCES / INDEX ne peuvent être accordés à un rôle (ORA-01931) ;
                // GRANT ALL ON obj TO user est appliqué directement au développeur lors de l'attribution du rôle APP_ALL.
                $sql = "GRANT SELECT, INSERT, UPDATE, DELETE, ALTER ON \"{$schema}\".\"{$tbl}\" TO {$roleName}";
            } elseif ($canSelect && $canDml) {
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
                if ($isAll) {
                    try {
                        $this->db->executeDdl("GRANT SELECT, INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$tbl}\" TO {$roleName}");
                        $granted[] = "TABLE {$tbl}";
                        continue;
                    } catch (\Throwable $e2) {}
                }
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
            if ($isAll) {
                try {
                    $this->db->executeDdl("GRANT SELECT, INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$vw}\" TO {$roleName}");
                    $granted[] = "VIEW {$vw}";
                } catch (\Throwable $e) {
                    try {
                        $this->db->executeDdl("GRANT SELECT ON \"{$schema}\".\"{$vw}\" TO {$roleName}");
                        $granted[] = "VIEW {$vw}";
                    } catch (\Throwable $e2) {
                        $errors[] = "VIEW {$vw}: " . $e2->getMessage();
                    }
                }
            } elseif ($canSelect) {
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
            if ($isAll) {
                try {
                    $this->db->executeDdl("GRANT SELECT, ALTER ON \"{$schema}\".\"{$seq}\" TO {$roleName}");
                    $granted[] = "SEQUENCE {$seq}";
                } catch (\Throwable $e) {
                    try {
                        $this->db->executeDdl("GRANT SELECT ON \"{$schema}\".\"{$seq}\" TO {$roleName}");
                        $granted[] = "SEQUENCE {$seq}";
                    } catch (\Throwable $e2) {
                        $errors[] = "SEQUENCE {$seq}: " . $e2->getMessage();
                    }
                }
            } elseif ($canSelect || $canDml) {
                $sql = "GRANT SELECT ON \"{$schema}\".\"{$seq}\" TO {$roleName}";
                try {
                    $this->db->executeDdl($sql);
                    $granted[] = "SEQUENCE {$seq}";
                } catch (\Throwable $e) {
                    $errors[] = "SEQUENCE {$seq}: " . $e->getMessage();
                }
            }
        }

        // 4. Objets exécutables (PROCEDURE, FUNCTION, PACKAGE, TYPE)
        if ($canExecute) {
            $execObjs = [];
            try {
                $execObjs = $this->db->query(
                    "SELECT OBJECT_NAME, OBJECT_TYPE FROM DBA_OBJECTS WHERE OWNER = :s AND OBJECT_TYPE IN ('PROCEDURE', 'FUNCTION', 'PACKAGE', 'TYPE') ORDER BY OBJECT_TYPE, OBJECT_NAME",
                    ['s' => $schema]
                );
            } catch (\Throwable $e) {
                try {
                    $execObjs = $this->db->query(
                        "SELECT OBJECT_NAME, OBJECT_TYPE FROM ALL_OBJECTS WHERE OWNER = :s AND OBJECT_TYPE IN ('PROCEDURE', 'FUNCTION', 'PACKAGE', 'TYPE') ORDER BY OBJECT_TYPE, OBJECT_NAME",
                        ['s' => $schema]
                    );
                } catch (\Throwable $e2) {}
            }

            foreach ($execObjs as $eo) {
                $oName = $eo['OBJECT_NAME'];
                $oType = $eo['OBJECT_TYPE'];
                try {
                    $this->db->executeDdl("GRANT EXECUTE ON \"{$schema}\".\"{$oName}\" TO {$roleName}");
                    $granted[] = "{$oType} {$oName}";
                } catch (\Throwable $e) {
                    $errors[] = "{$oType} {$oName}: " . $e->getMessage();
                }
            }
        }

        // 5. Vues Matérialisées (MATERIALIZED VIEW)
        if ($canSelect || $isAll) {
            $mviews = [];
            try {
                $mviews = $this->db->query("SELECT MVIEW_NAME FROM DBA_MVIEWS WHERE OWNER = :s ORDER BY MVIEW_NAME", ['s' => $schema]);
            } catch (\Throwable $e) {
                try {
                    $mviews = $this->db->query("SELECT MVIEW_NAME FROM ALL_MVIEWS WHERE OWNER = :s ORDER BY MVIEW_NAME", ['s' => $schema]);
                } catch (\Throwable $e2) {}
            }

            foreach ($mviews as $mv) {
                $mvName = $mv['MVIEW_NAME'];
                try {
                    $this->db->executeDdl("GRANT SELECT ON \"{$schema}\".\"{$mvName}\" TO {$roleName}");
                    $granted[] = "MATERIALIZED VIEW {$mvName}";
                } catch (\Throwable $e) {
                    $errors[] = "MATERIALIZED VIEW {$mvName}: " . $e->getMessage();
                }
            }
        }

        return ['granted' => $granted, 'errors' => $errors];
    }

    /**
     * Liste les objets d'un schéma couverts par le profil APP_ALL :
     * TABLE, VIEW, SEQUENCE, PROCEDURE, FUNCTION, PACKAGE, TYPE, MATERIALIZED VIEW
     *
     * @return array<int, array{OBJECT_NAME: string, OBJECT_TYPE: string}>
     */
    private function getSchemaObjectsForAllProfile(string $schema): array
    {
        $schema = strtoupper(trim($schema));
        $sqlDba = "SELECT OBJECT_NAME, OBJECT_TYPE
                   FROM DBA_OBJECTS
                   WHERE OWNER = :s
                     AND OBJECT_TYPE IN (
                         'TABLE', 'VIEW', 'SEQUENCE', 'PROCEDURE',
                         'FUNCTION', 'PACKAGE', 'TYPE', 'MATERIALIZED VIEW'
                     )
                     AND OBJECT_NAME NOT LIKE 'BIN\$%'
                   ORDER BY OBJECT_TYPE, OBJECT_NAME";
        $sqlAll = str_replace('DBA_OBJECTS', 'ALL_OBJECTS', $sqlDba);

        $rows = [];
        try {
            $rows = $this->db->query($sqlDba, ['s' => $schema]);
        } catch (\Throwable $e) {
            try {
                $rows = $this->db->query($sqlAll, ['s' => $schema]);
            } catch (\Throwable $e2) {}
        }

        // Déduplication par nom d'objet (ex: une MATERIALIZED VIEW et sa table conteneur partagent le même nom)
        $unique = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = strtoupper(trim((string)($row['OBJECT_NAME'] ?? '')));
            $type = strtoupper(trim((string)($row['OBJECT_TYPE'] ?? '')));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $unique[] = ['OBJECT_NAME' => $name, 'OBJECT_TYPE' => $type];
        }

        return $unique;
    }

    /**
     * Accorde directement GRANT ALL ON "SCHEMA"."OBJ" TO USER
     * pour tous les objets du schéma (TABLE, VIEW, SEQUENCE, PROCEDURE, FUNCTION, PACKAGE, TYPE, MATERIALIZED VIEW).
     * Utilisé lorsque un rôle de profil APP_ALL est attribué à un développeur.
     */
    public function grantAllSchemaObjectsToUser(string $schema, string $oracleUser): array
    {
        $schema = strtoupper(trim($schema));
        $oracleUser = strtoupper(trim($oracleUser));
        $granted = [];
        $errors = [];

        $objects = $this->getSchemaObjectsForAllProfile($schema);
        foreach ($objects as $obj) {
            $objName = $obj['OBJECT_NAME'];
            $objType = $obj['OBJECT_TYPE'];
            try {
                $this->db->executeDdl("GRANT ALL ON \"{$schema}\".\"{$objName}\" TO {$oracleUser}");
                $granted[] = "{$objType} {$objName}";
            } catch (\Throwable $e) {
                $errors[] = "{$objType} {$objName}: " . $e->getMessage();
            }
        }

        return ['granted' => $granted, 'errors' => $errors];
    }

    /**
     * Révoque les privilèges GRANT ALL accordés directement à un utilisateur lors de la révocation d'un rôle APP_ALL
     */
    public function revokeAllSchemaObjectsFromUser(string $schema, string $oracleUser): void
    {
        $schema = strtoupper(trim($schema));
        $oracleUser = strtoupper(trim($oracleUser));

        $objects = $this->getSchemaObjectsForAllProfile($schema);
        foreach ($objects as $obj) {
            $objName = $obj['OBJECT_NAME'];
            try {
                $this->db->executeDdl("REVOKE ALL ON \"{$schema}\".\"{$objName}\" FROM {$oracleUser}");
            } catch (\Throwable $e) {}
        }
    }

    /**
     * Accorde le quota illimité sur les tablespaces du schéma cible au compte développeur.
     * Résout l'erreur Oracle ORA-01950 (pas de privilèges sur le tablespace).
     *
     * Note technique Oracle : Le privilège UNLIMITED TABLESPACE et les quotas ne peuvent pas
     * être accordés à un rôle (ORA-01931). Ils doivent obligatoirement être accordés à l'utilisateur.
     */
    public function grantTablespaceQuotaForSchema(string $oracleUser, string $schema, ?string $localRole = null): array
    {
        $oracleUser = strtoupper(trim($oracleUser));
        $schema = strtoupper(trim($schema));
        $granted = [];
        $errors = [];

        // 1. Tenter sur le rôle local si demandé (Oracle retournera ORA-01931 si restreint)
        if (!empty($localRole)) {
            $localRole = strtoupper(trim($localRole));
            try {
                $this->db->executeDdl("GRANT UNLIMITED TABLESPACE TO {$localRole}");
                $granted[] = "UNLIMITED TABLESPACE to role {$localRole}";
            } catch (\Throwable $e) {
                // Normal sous Oracle : ORA-01931: cannot grant UNLIMITED TABLESPACE to a role
            }
        }

        // 2. Accorder UNLIMITED TABLESPACE directement au développeur dans cette PDB
        try {
            $this->db->executeDdl("GRANT UNLIMITED TABLESPACE TO {$oracleUser}");
            $granted[] = "UNLIMITED TABLESPACE to user {$oracleUser}";
        } catch (\Throwable $e) {
            try {
                $this->db->executeDdl("GRANT UNLIMITED TABLESPACE TO {$oracleUser} CONTAINER = CURRENT");
                $granted[] = "UNLIMITED TABLESPACE to user {$oracleUser}";
            } catch (\Throwable $e2) {
                $errors[] = "GRANT UNLIMITED TABLESPACE: " . $e2->getMessage();
            }
        }

        // 3. Découvrir tous les tablespaces associés au schéma cible
        $tablespaces = [];

        // a) Tablespace par défaut du schéma cible
        if (!empty($schema)) {
            try {
                $userRow = $this->db->queryOne("SELECT DEFAULT_TABLESPACE FROM DBA_USERS WHERE USERNAME = :s", ['s' => $schema]);
                if (!empty($userRow['DEFAULT_TABLESPACE'])) {
                    $tablespaces[] = strtoupper(trim($userRow['DEFAULT_TABLESPACE']));
                }
            } catch (\Throwable $e) {
                try {
                    $userRow = $this->db->queryOne("SELECT DEFAULT_TABLESPACE FROM ALL_USERS WHERE USERNAME = :s", ['s' => $schema]);
                    if (!empty($userRow['DEFAULT_TABLESPACE'])) {
                        $tablespaces[] = strtoupper(trim($userRow['DEFAULT_TABLESPACE']));
                    }
                } catch (\Throwable $e2) {}
            }

            // b) Tablespaces contenant des tables du schéma cible
            try {
                $tsRows = $this->db->query("
                    SELECT DISTINCT TABLESPACE_NAME 
                    FROM DBA_TABLES 
                    WHERE OWNER = :s AND TABLESPACE_NAME IS NOT NULL
                ", ['s' => $schema]);
                foreach ($tsRows as $r) {
                    if (!empty($r['TABLESPACE_NAME'])) {
                        $tablespaces[] = strtoupper(trim($r['TABLESPACE_NAME']));
                    }
                }
            } catch (\Throwable $e) {}

            // c) Tablespaces contenant des index du schéma cible
            try {
                $idxRows = $this->db->query("
                    SELECT DISTINCT TABLESPACE_NAME 
                    FROM DBA_INDEXES 
                    WHERE OWNER = :s AND TABLESPACE_NAME IS NOT NULL
                ", ['s' => $schema]);
                foreach ($idxRows as $ir) {
                    if (!empty($ir['TABLESPACE_NAME'])) {
                        $tablespaces[] = strtoupper(trim($ir['TABLESPACE_NAME']));
                    }
                }
            } catch (\Throwable $e) {}
        }

        // d) Tous les tablespaces permanents utilisateur de la PDB (USERS, DATA, etc.)
        try {
            $allTs = $this->db->query("
                SELECT TABLESPACE_NAME 
                FROM DBA_TABLESPACES 
                WHERE CONTENTS = 'PERMANENT' 
                  AND TABLESPACE_NAME NOT IN ('SYSTEM', 'SYSAUX')
            ");
            foreach ($allTs as $tsRow) {
                if (!empty($tsRow['TABLESPACE_NAME'])) {
                    $tablespaces[] = strtoupper(trim($tsRow['TABLESPACE_NAME']));
                }
            }
        } catch (\Throwable $e) {}

        // e) Tablespace 'USERS' par défaut
        $tablespaces[] = 'USERS';

        $tablespaces = array_unique(array_filter($tablespaces));

        // 4. Attribuer QUOTA UNLIMITED sur chaque tablespace trouvé pour le développeur ET pour le schéma propriétaire
        foreach ($tablespaces as $ts) {
            // Pour le développeur
            try {
                $this->db->executeDdl("ALTER USER {$oracleUser} QUOTA UNLIMITED ON \"{$ts}\"");
                $granted[] = "QUOTA DEV ON \"{$ts}\"";
            } catch (\Throwable $e) {
                try {
                    $this->db->executeDdl("ALTER USER {$oracleUser} QUOTA UNLIMITED ON {$ts}");
                    $granted[] = "QUOTA DEV ON {$ts}";
                } catch (\Throwable $e2) {
                    $errors[] = "QUOTA DEV ON {$ts}: " . $e2->getMessage();
                }
            }

            // CRITIQUE : Pour le schéma propriétaire des tables (ex: SARAHTEST).
            // En Oracle 19c avec DEFERRED_SEGMENT_CREATION, le premier INSERT dans une table vide
            // alloue le premier segment au nom du propriétaire du schéma. Si le propriétaire n'a pas
            // de quota sur le tablespace, Oracle lève ORA-01950, même si le développeur est UNLIMITED !
            if (!empty($schema)) {
                try {
                    $this->db->executeDdl("ALTER USER \"{$schema}\" QUOTA UNLIMITED ON \"{$ts}\"");
                    $granted[] = "QUOTA SCHEMA \"{$schema}\" ON \"{$ts}\"";
                } catch (\Throwable $se1) {
                    try {
                        $this->db->executeDdl("ALTER USER {$schema} QUOTA UNLIMITED ON {$ts}");
                        $granted[] = "QUOTA SCHEMA {$schema} ON {$ts}";
                    } catch (\Throwable $se2) {}
                }
            }
        }

        // Accorder également UNLIMITED TABLESPACE au schéma propriétaire si possible
        if (!empty($schema)) {
            try {
                $this->db->executeDdl("GRANT UNLIMITED TABLESPACE TO \"{$schema}\"");
            } catch (\Throwable $e) {
                try {
                    $this->db->executeDdl("GRANT UNLIMITED TABLESPACE TO {$schema}");
                } catch (\Throwable $e2) {}
            }
        }

        return ['granted' => $granted, 'errors' => $errors];
    }

    /**
     * Synchronise tous les rôles applicatifs et tous les quotas tablespace de tous les développeurs dans une PDB
     */
    public function syncAllPdbAccessAndQuotas(string $pdbName): array
    {
        $this->db->switchContainer($pdbName);

        $syncedRoles = 0;
        $updatedDevs = 0;

        // 1. Synchroniser tous les rôles applicatifs enregistrés dans cette PDB
        $roles = [];
        try {
            $roles = $this->db->query("SELECT ROLE_ID, ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE IS_ACTIVE = 'Y'");
        } catch (\Throwable $e) {}

        foreach ($roles as $r) {
            $this->syncSchemaPrivilegesToRole($r['TARGET_SCHEMA'], $r['ROLE_NAME'], $r['PROFILE_TYPE'] ?? 'APP_READ_WRITE');
            $syncedRoles++;
        }

        // 1b. Pour les rôles APP_ALL attribués, synchroniser également GRANT ALL ON obj TO user
        try {
            $allAssignments = $this->db->query(
                "SELECT da.USERNAME, da.TARGET_SCHEMA
                 FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                 INNER JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                 WHERE da.STATUS = 'ACTIVE' AND UPPER(TRIM(ar.PROFILE_TYPE)) IN ('APP_ALL', 'ALL')"
            );
            foreach ($allAssignments as $aa) {
                if (!empty($aa['USERNAME']) && !empty($aa['TARGET_SCHEMA'])) {
                    $this->grantAllSchemaObjectsToUser($aa['TARGET_SCHEMA'], $aa['USERNAME']);
                }
            }
        } catch (\Throwable $e) {}

        // 2. Détecter tous les développeurs (depuis ZIRIOAM_DEVELOPER_ACCESS et ZIRIOAM_DEVELOPERS)
        $devUsernames = [];
        $activePdbBaseUsers = [];
        try {
            $devs = $this->db->query("SELECT DISTINCT USERNAME FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS WHERE STATUS = 'ACTIVE'");
            foreach ($devs as $d) {
                if (!empty($d['USERNAME'])) {
                    $uNorm = strtoupper(trim($d['USERNAME']));
                    $uBase = str_starts_with($uNorm, 'C##') ? substr($uNorm, 3) : $uNorm;
                    $activePdbBaseUsers[$uBase] = true;
                    $devUsernames[] = $d['USERNAME'];
                }
            }
        } catch (\Throwable $e) {}

        // Récupérer les utilisateurs développeurs depuis CDB$ROOT
        try {
            $prev = $this->db->getCurrentContainer();
            $this->db->switchContainer('CDB$ROOT');
            $rootDevs = $this->db->query("SELECT ORACLE_USER FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS WHERE STATUS = 'ACTIVE'");
            foreach ($rootDevs as $rd) {
                if (!empty($rd['ORACLE_USER'])) $devUsernames[] = $rd['ORACLE_USER'];
            }
            $this->db->switchContainer($prev);
        } catch (\Throwable $e) {}

        $devUsernames = array_unique(array_filter($devUsernames));

        // 3. Pour chaque développeur, appliquer les quotas illimités et restaurer les rôles de session / DEFAULT ROLE ALL
        $vaultConn = null;
        try {
            $vaultConn = (new OracleConnectionService())->getVaultConnection();
            if ($vaultConn) {
                $vaultConn->switchContainer($pdbName);
            }
        } catch (\Throwable $ve) {
            $vaultConn = null;
        }

        try {
            foreach ($devUsernames as $u) {
                $norm = strtoupper(trim($u));
                $base = str_starts_with($norm, 'C##') ? substr($norm, 3) : $norm;
                $localRole = 'RL_' . $base;
                $hasPdbAccess = isset($activePdbBaseUsers[$base]);

                $this->ensureDeveloperPdbSessionAndDefaultRoles($pdbName, $u, $hasPdbAccess, $vaultConn);
                $this->grantTablespaceQuotaForSchema($u, '', $localRole);
                $updatedDevs++;
            }
        } finally {
            if ($vaultConn) {
                try { $vaultConn->close(); } catch (\Throwable $ce) {}
            }
        }

        return [
            'synced_roles' => $syncedRoles,
            'updated_devs' => $updatedDevs,
        ];
    }

    /**
     * Restaure dans la PDB cible le rôle commun, les rôles par défaut (DEFAULT ROLE ALL)
     * et, si le développeur dispose d'accès actifs dans cette PDB, le rôle local RL_<USER> + CREATE SESSION.
     * Utilise la connexion Database Vault en repli si ALTER USER est restreint (ORA-47410).
     */
    private function ensureDeveloperPdbSessionAndDefaultRoles(
        string $pdbName,
        string $username,
        bool $ensureLocalRole = true,
        ?OracleConnection $vaultConn = null
    ): void {
        $norm = strtoupper(trim($username));
        $base = str_starts_with($norm, 'C##') ? substr($norm, 3) : $norm;
        $oracleUser = 'C##' . $base;
        $commonRole = 'C##RL_' . $base;
        $localRole  = 'RL_' . $base;

        try { $this->db->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $e) {}

        if ($ensureLocalRole) {
            try { $this->db->executeDdl("CREATE ROLE {$localRole}"); } catch (\Throwable $e) {}
            try { $this->db->executeDdl("GRANT CREATE SESSION TO {$localRole}"); } catch (\Throwable $e) {}
            try { $this->db->executeDdl("GRANT ZIRIOAM_DEV_BASE_ROLE TO {$localRole}"); } catch (\Throwable $e) {}
            try { $this->db->executeDdl("GRANT {$localRole} TO {$commonRole} CONTAINER = CURRENT"); } catch (\Throwable $e) {}
            try { $this->db->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $e) {}
        }

        $defaultErr = null;
        try {
            $this->db->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE ALL");
            try { $this->db->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK"); } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            $defaultErr = $e;
        }

        if ($defaultErr !== null) {
            $ownsVaultConn = false;
            $vc = $vaultConn;
            if (!$vc) {
                try {
                    $vc = (new OracleConnectionService())->getVaultConnection();
                    if ($vc) {
                        $vc->switchContainer($pdbName);
                        $ownsVaultConn = true;
                    }
                } catch (\Throwable $ve) {
                    $vc = null;
                }
            }

            if ($vc) {
                try {
                    try { $vc->executeDdl("GRANT {$commonRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $ve) {}
                    if ($ensureLocalRole) {
                        try { $vc->executeDdl("GRANT {$localRole} TO {$oracleUser} CONTAINER = CURRENT"); } catch (\Throwable $ve) {}
                    }
                    $vc->executeDdl("ALTER USER {$oracleUser} DEFAULT ROLE ALL");
                    try { $vc->executeDdl("ALTER USER {$oracleUser} ACCOUNT UNLOCK"); } catch (\Throwable $ve) {}
                } catch (\Throwable $ve) {}

                if ($ownsVaultConn) {
                    try { $vc->close(); } catch (\Throwable $ce) {}
                }
            }
        }
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

            // 2. Accorder CREATE SESSION au rôle commun C##RL_<USER> dans toute la CDB
            // Nécessaire pour les opérations self-service (/UpdPwd et /selfservice)
            try {
                $this->db->executeDdl("GRANT CREATE SESSION TO {$commonRole} CONTAINER = ALL");
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
                $profileType = strtoupper(trim((string)($roleRow['PROFILE_TYPE'] ?? 'APP_READ_WRITE')));
                try {
                    $this->db->executeDdl("GRANT {$roleName} TO {$localRole}");
                } catch (\Throwable $e) {}

                // Synchroniser automatiquement les objets du schéma vers ce rôle
                $this->syncSchemaPrivilegesToRole($schema, $roleName, $profileType);

                // Règle spécifique APP_ALL : accorder GRANT ALL ON obj TO user sur tous les objets du schéma
                if (in_array($profileType, ['APP_ALL', 'ALL'], true)) {
                    $this->grantAllSchemaObjectsToUser($schema, $oracleUser);
                }
            }
        }

        // 4. Ensuite accorder le rôle local au rôle commun : grant RL_USER1 to C##RL_USER1 container=current
        try {
            $this->db->executeDdl("GRANT {$localRole} TO {$commonRole} CONTAINER = CURRENT");
        } catch (\Throwable $e) {
            // Tolérance si Oracle restreint l'attribution d'un rôle local à un rôle commun (ORA-65033/ORA-65030)
        }

        // 5. Attribuer également le rôle local + rôle commun + DEFAULT ROLE ALL (avec repli Vault) dans la PDB
        $this->ensureDeveloperPdbSessionAndDefaultRoles($pdbName, $oracleUser, true);

        // 6. Résolution ORA-01950 : Accorder le quota illimité sur les tablespaces du schéma cible et UNLIMITED TABLESPACE
        try {
            $this->grantTablespaceQuotaForSchema($oracleUser, $schema, $localRole);
        } catch (\Throwable $e) {}

        // Pré-allocation des segments pour les tables vides existantes avec DEFERRED_SEGMENT_CREATION
        // Empêche définitivement l'erreur ORA-01950 lors du premier INSERT par un développeur
        if (!empty($schema)) {
            try {
                $emptyTables = $this->db->query("
                    SELECT TABLE_NAME FROM DBA_TABLES 
                    WHERE OWNER = :s AND SEGMENT_CREATED = 'NO'
                ", ['s' => $schema]);
                foreach ($emptyTables as $et) {
                    $tbl = $et['TABLE_NAME'] ?? '';
                    if (!empty($tbl)) {
                        try {
                            $this->db->executeDdl("ALTER TABLE \"{$schema}\".\"{$tbl}\" ALLOCATE EXTENT");
                        } catch (\Throwable $ae) {}
                    }
                }
            } catch (\Throwable $te) {}
        }

        // 7. Mettre à jour la matrice ZIRIOAM_DEVELOPER_ACCESS
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

        $row = $this->db->queryOne("SELECT da.USERNAME, da.TARGET_SCHEMA, ar.ROLE_NAME, ar.PROFILE_TYPE 
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

            if (in_array(strtoupper(trim((string)($row['PROFILE_TYPE'] ?? ''))), ['APP_ALL', 'ALL'], true)) {
                $this->revokeAllSchemaObjectsFromUser($schema, $normUser);
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

