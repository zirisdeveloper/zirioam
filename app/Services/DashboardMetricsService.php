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
        $pdbsRow = $this->db->queryOne("
            SELECT 
                COUNT(*) AS TOTAL_PDBS,
                SUM(CASE WHEN OPEN_MODE = 'READ WRITE' THEN 1 ELSE 0 END) AS OPEN_PDBS,
                SUM(CASE WHEN OPEN_MODE != 'READ WRITE' THEN 1 ELSE 0 END) AS CLOSED_PDBS
            FROM V\$PDBS
        ");

        $totalPdbs = (int)($pdbsRow['TOTAL_PDBS'] ?? 0);
        $openPdbs = (int)($pdbsRow['OPEN_PDBS'] ?? 0);
        $closedPdbs = (int)($pdbsRow['CLOSED_PDBS'] ?? 0);

        // 2. Métriques des Développeurs dans CDB$ROOT
        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        $devCountRow = $this->db->queryOne("SELECT COUNT(*) AS CNT FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS");
        $registeredDevs = (int)($devCountRow['CNT'] ?? 0);

        $createdAccountsRow = $this->db->queryOne("
            SELECT COUNT(*) AS CNT FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS d
            WHERE EXISTS (SELECT 1 FROM CDB_USERS u WHERE u.USERNAME = d.ORACLE_USER AND u.COMMON = 'YES')
        ");
        $createdAccounts = (int)($createdAccountsRow['CNT'] ?? 0);
        $pendingAccounts = max(0, $registeredDevs - $createdAccounts);

        // 3. Métriques d'Autorisations (depuis les PDBs ou PDB active)
        $readCount = 0;
        $writeCount = 0;
        $rwCount = 0;
        $ddlCount = 0;

        try {
            // Tentative de lecture sur PDB ouverte
            $firstPdb = $this->db->queryOne("SELECT NAME FROM V\$PDBS WHERE OPEN_MODE = 'READ WRITE' AND ROWNUM = 1");
            if ($firstPdb) {
                $this->db->switchContainer($firstPdb['NAME']);
                $matrixRows = $this->db->query("
                    SELECT ar.PROFILE_TYPE, da.CAN_DDL_CREATE, da.CAN_DDL_ALTER, da.CAN_DDL_DROP
                    FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS da
                    LEFT JOIN ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES ar ON da.ROLE_ID = ar.ROLE_ID
                    WHERE da.STATUS = 'ACTIVE'
                ");

                foreach ($matrixRows as $m) {
                    $pt = $m['PROFILE_TYPE'] ?? '';
                    if ($pt === 'APP_READ') $readCount++;
                    elseif ($pt === 'APP_WRITE') $writeCount++;
                    elseif ($pt === 'APP_READ_WRITE') $rwCount++;

                    if (($m['CAN_DDL_CREATE'] ?? 0) == 1 || ($m['CAN_DDL_ALTER'] ?? 0) == 1 || ($m['CAN_DDL_DROP'] ?? 0) == 1) {
                        $ddlCount++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignorer si PDB non encore installée
        }

        // 4. Activité récente (Dernières opérations d'audit)
        $recentActivity = [];
        try {
            $this->db->switchContainer('CDB$ROOT');
            $sql = "SELECT AUDIT_ID, TO_CHAR(EVENT_TIME, 'YYYY-MM-DD HH24:MI:SS') AS EVENT_TIME, 
                           DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS
                    FROM C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT
                    ORDER BY EVENT_TIME DESC";
            $recentActivity = $this->db->query($sql);
            if (count($recentActivity) > 10) {
                $recentActivity = array_slice($recentActivity, 0, 10);
            }
        } catch (\Throwable $e) {
            // Ignorer
        }

        return [
            'total_pdbs' => $totalPdbs,
            'open_pdbs' => $openPdbs,
            'closed_pdbs' => $closedPdbs,
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
}

