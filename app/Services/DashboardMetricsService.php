<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service de Métriques Réelles pour le Tableau de Bord (Section 17)
 */
class DashboardMetricsService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Récupère toutes les métriques réelles d'administration
     */
    public function getMetrics(): array
    {
        // 1. Métriques des PDBs
        $totalPdbs = 0;
        $openPdbsCount = 0;
        $closedPdbsCount = 0;

        try {
            $pdbsRow = $this->db->queryOne("
                SELECT 
                    COUNT(*) AS TOTAL_PDBS,
                    SUM(CASE WHEN OPEN_MODE = 'READ WRITE' THEN 1 ELSE 0 END) AS OPEN_PDBS,
                    SUM(CASE WHEN OPEN_MODE != 'READ WRITE' THEN 1 ELSE 0 END) AS CLOSED_PDBS
                FROM V\$PDBS
            ");

            $totalPdbs = (int)($pdbsRow['TOTAL_PDBS'] ?? 0);
            $openPdbsCount = (int)($pdbsRow['OPEN_PDBS'] ?? 0);
            $closedPdbsCount = (int)($pdbsRow['CLOSED_PDBS'] ?? 0);
        } catch (\Throwable $e) {}

        // 2. Métriques des Développeurs dans CDB$ROOT
        $registeredDevs = 0;
        $createdAccounts = 0;
        $pendingAccounts = 0;
        $knownDevUsernames = [];

        try {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }

            $devRows = $this->db->query("SELECT USERNAME, ORACLE_USER FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS");
            $registeredDevs = count($devRows);
            foreach ($devRows as $dr) {
                $u = strtoupper(trim((string)($dr['USERNAME'] ?? '')));
                if (!empty($u)) {
                    $knownDevUsernames[] = $u;
                }
            }

            $createdAccountsRow = $this->db->queryOne("
                SELECT COUNT(*) AS CNT FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS d
                WHERE EXISTS (SELECT 1 FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES')
            ");
            $createdAccounts = (int)($createdAccountsRow['CNT'] ?? 0);
            $pendingAccounts = max(0, $registeredDevs - $createdAccounts);
        } catch (\Throwable $e) {}

        // Rôles d'identité développeurs (ex: RL_SON6987, C##RL_SON6987) à exclure des rôles applicatifs
        $devIdentityRoles = [];
        foreach ($knownDevUsernames as $ku) {
            $devIdentityRoles[] = 'RL_' . $ku;
            $devIdentityRoles[] = 'C##RL_' . $ku;
        }

        // 3. Métriques d'Autorisations Granulaires (parcours de toutes les PDBs ouvertes)
        $readCount = 0;
        $writeCount = 0;
        $rwCount = 0;
        $ddlCount = 0;

        $seenGrants = []; // format: "$pdb:$dev:$roleOrSchema" => true
        $seenDdl = [];    // format: "$pdb:$dev:$schema" => true

        try {
            // Liste de toutes les PDBs ouvertes en READ WRITE (hors PDB$SEED)
            $openPdbs = $this->db->query("
                SELECT NAME FROM V\$PDBS 
                WHERE OPEN_MODE = 'READ WRITE' 
                  AND UPPER(NAME) NOT IN ('PDB\$SEED')
                ORDER BY NAME
            ");

            foreach ($openPdbs as $pdb) {
                $pdbName = strtoupper(trim($pdb['NAME'] ?? ''));
                if (empty($pdbName)) continue;

                try {
                    $this->db->switchContainer($pdbName);

                    // A. Vérifier la table ZIRIOAM_DEVELOPER_ACCESS si elle existe dans cette PDB
                    try {
                        $matrixRows = $this->db->query("
                            SELECT da.ACCESS_ID, da.USERNAME, da.TARGET_SCHEMA, da.ROLE_ID,
                                   ar.ROLE_NAME, ar.PROFILE_TYPE,
                                   da.CAN_DDL_CREATE, da.CAN_DDL_ALTER, da.CAN_DDL_DROP
                            FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                            LEFT JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                            WHERE da.STATUS = 'ACTIVE'
                        ");

                        foreach ($matrixRows as $m) {
                            $user = strtoupper(trim((string)($m['USERNAME'] ?? '')));
                            $schema = strtoupper(trim((string)($m['TARGET_SCHEMA'] ?? '')));
                            $rname = strtoupper(trim((string)($m['ROLE_NAME'] ?? '')));
                            $pt = strtoupper(trim((string)($m['PROFILE_TYPE'] ?? '')));

                            // DDL contrôlé
                            $hasDdl = (($m['CAN_DDL_CREATE'] ?? 0) == 1 || ($m['CAN_DDL_ALTER'] ?? 0) == 1 || ($m['CAN_DDL_DROP'] ?? 0) == 1);
                            if ($hasDdl) {
                                $ddlKey = "{$pdbName}:{$user}:{$schema}";
                                if (!isset($seenDdl[$ddlKey])) {
                                    $seenDdl[$ddlKey] = true;
                                    $ddlCount++;
                                }
                            }

                            // Privilèges de données DML / Rôles
                            if (!empty($rname) || !empty($pt)) {
                                $grantKey = "{$pdbName}:{$user}:" . ($rname ?: $schema);
                                if (!isset($seenGrants[$grantKey])) {
                                    $seenGrants[$grantKey] = true;
                                    $this->classifyRoleProfile($pt, $rname, $readCount, $writeCount, $rwCount);
                                }
                            }
                        }
                    } catch (\Throwable $te) {
                        // Table ZIRIOAM_DEVELOPER_ACCESS potentiellement absente dans cette PDB
                    }

                    // B. Vérifier le dictionnaire Oracle local (DBA_ROLE_PRIVS) dans cette PDB
                    try {
                        $rolePrivRows = $this->db->query("
                            SELECT GRANTEE, GRANTED_ROLE 
                            FROM DBA_ROLE_PRIVS 
                            WHERE (GRANTEE LIKE 'C##%' OR GRANTEE LIKE 'RL_%')
                              AND (GRANTED_ROLE LIKE 'RL_%' OR GRANTED_ROLE LIKE 'R_%')
                              AND GRANTED_ROLE NOT IN ('CONNECT', 'RESOURCE', 'DBA', 'ZIRIOAM_DEV_BASE_ROLE')
                              AND GRANTED_ROLE NOT LIKE 'C##RL_%'
                        ");

                        foreach ($rolePrivRows as $rp) {
                            $grantee = strtoupper(trim((string)($rp['GRANTEE'] ?? '')));
                            $grantedRole = strtoupper(trim((string)($rp['GRANTED_ROLE'] ?? '')));

                            // Ignorer les rôles d'identité utilisateur (ex: RL_SON6987 accordé à C##RL_SON6987)
                            if (in_array($grantedRole, $devIdentityRoles)) {
                                continue;
                            }
                            if ($grantedRole === 'RL_' . substr($grantee, 3) || $grantedRole === 'RL_' . substr($grantee, 5)) {
                                continue;
                            }

                            $baseDev = str_starts_with($grantee, 'RL_') ? substr($grantee, 3) : (str_starts_with($grantee, 'C##') ? substr($grantee, 3) : $grantee);
                            $grantKey = "{$pdbName}:{$baseDev}:{$grantedRole}";

                            if (!isset($seenGrants[$grantKey])) {
                                $seenGrants[$grantKey] = true;
                                $this->classifyRoleProfile('', $grantedRole, $readCount, $writeCount, $rwCount);
                            }
                        }
                    } catch (\Throwable $rpe) {
                        // DBA_ROLE_PRIVS non accessible dans cette PDB
                    }

                } catch (\Throwable $pe) {
                    // Erreur spécifique à cette PDB, continuer sur les autres
                }
            }
        } catch (\Throwable $e) {
            // Erreur globale sur la détection des PDBs
        } finally {
            // Toujours repositionner la session sur CDB$ROOT
            try {
                $this->db->switchContainer('CDB$ROOT');
            } catch (\Throwable $e) {}
        }

        // C. Fallback via CDB_ROLE_PRIVS si aucun accès n'a encore été détecté depuis les PDBs
        if (($readCount + $writeCount + $rwCount) === 0) {
            try {
                $currentCon = $this->db->getCurrentContainer();
                if ($currentCon !== 'CDB$ROOT') {
                    $this->db->switchContainer('CDB$ROOT');
                }

                $cdbRolePrivs = $this->db->query("
                    SELECT GRANTEE, GRANTED_ROLE, CON_ID 
                    FROM CDB_ROLE_PRIVS 
                    WHERE (GRANTEE LIKE 'C##%' OR GRANTEE LIKE 'RL_%')
                      AND (GRANTED_ROLE LIKE 'RL_%' OR GRANTED_ROLE LIKE 'R_%')
                      AND GRANTED_ROLE NOT IN ('CONNECT', 'RESOURCE', 'DBA', 'ZIRIOAM_DEV_BASE_ROLE')
                      AND GRANTED_ROLE NOT LIKE 'C##RL_%'
                      AND CON_ID > 2
                ");

                foreach ($cdbRolePrivs as $crp) {
                    $grantee = strtoupper(trim((string)($crp['GRANTEE'] ?? '')));
                    $grantedRole = strtoupper(trim((string)($crp['GRANTED_ROLE'] ?? '')));
                    $conId = (int)($crp['CON_ID'] ?? 0);

                    if (in_array($grantedRole, $devIdentityRoles)) {
                        continue;
                    }
                    if ($grantedRole === 'RL_' . substr($grantee, 3) || $grantedRole === 'RL_' . substr($grantee, 5)) {
                        continue;
                    }

                    $baseDev = str_starts_with($grantee, 'RL_') ? substr($grantee, 3) : (str_starts_with($grantee, 'C##') ? substr($grantee, 3) : $grantee);
                    $grantKey = "CON_{$conId}:{$baseDev}:{$grantedRole}";

                    if (!isset($seenGrants[$grantKey])) {
                        $seenGrants[$grantKey] = true;
                        $this->classifyRoleProfile('', $grantedRole, $readCount, $writeCount, $rwCount);
                    }
                }
            } catch (\Throwable $cdbErr) {}
        }

        // 4. Activité récente (Dernières opérations d'audit dans CDB$ROOT)
        $recentActivity = [];
        try {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }
            $sql = "SELECT AUDIT_ID, TO_CHAR(EVENT_TIME, 'YYYY-MM-DD HH24:MI:SS') AS EVENT_TIME, 
                           DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT
                    ORDER BY EVENT_TIME DESC";
            $recentActivity = $this->db->query($sql);
            if (count($recentActivity) > 10) {
                $recentActivity = array_slice($recentActivity, 0, 10);
            }
        } catch (\Throwable $e) {}

        return [
            'total_pdbs' => $totalPdbs,
            'open_pdbs' => $openPdbsCount,
            'closed_pdbs' => $closedPdbsCount,
            'registered_devs' => $registeredDevs,
            'created_accounts' => $createdAccounts,
            'pending_accounts' => $pendingAccounts,
            'access_read' => $readCount,
            'access_write' => $writeCount,
            'access_rw' => $rwCount,
            'access_ddl' => $ddlCount,
            'recent_activity' => $recentActivity,
        ];
    }

    /**
     * Classe le profil d'accès (READ, WRITE, READ_WRITE) de manière robuste
     */
    public function classifyRoleProfile(string $profileType, string $roleName, int &$readCount, int &$writeCount, int &$rwCount): void
    {
        $pt = strtoupper(trim($profileType));
        $rn = strtoupper(trim($roleName));

        // Test READ_WRITE / TOUS DROITS
        if (in_array($pt, ['APP_READ_WRITE', 'READ_WRITE', 'RW', 'READ-WRITE', 'APP_ALL', 'ALL'])
            || str_ends_with($rn, '_RW')
            || str_ends_with($rn, '_ALL')) {
            $rwCount++;
            return;
        }

        // Test WRITE
        if (in_array($pt, ['APP_WRITE', 'WRITE', 'WO'])
            || str_ends_with($rn, '_WRITE')
            || str_ends_with($rn, '_WO')) {
            $writeCount++;
            return;
        }

        // Test READ
        if (in_array($pt, ['APP_READ', 'READ', 'RO', 'READ_ONLY', 'APP_EXECUTE', 'EXECUTE', 'EXEC'])
            || str_ends_with($rn, '_READ')
            || str_ends_with($rn, '_RO')
            || str_ends_with($rn, '_EXEC')) {
            $readCount++;
            return;
        }

        // Par défaut pour tout rôle applicatif attribué sans profil explicite
        $rwCount++;
    }
}
