<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;

/**
 * Contrôleur d'Audit et Traçabilité des Opérations (Section 16)
 */
class AuditController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    public function index(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $activePdb = $this->session->get('current_pdb');
        $scope = (string)$request->query('scope', 'CDB'); // 'CDB' ou 'PDB'

        $auditLogs = [];

        try {
            if ($scope === 'CDB' || empty($activePdb)) {
                $db->switchContainer('CDB$ROOT');
                $sql = "SELECT AUDIT_ID, TO_CHAR(EVENT_TIME, 'YYYY-MM-DD HH24:MI:SS') AS EVENT_TIME, 
                               DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS, CLIENT_IP
                        FROM C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT
                        ORDER BY EVENT_TIME DESC";
                $auditLogs = $db->query($sql);
            } else {
                $db->switchContainer($activePdb);
                $sql = "SELECT AUDIT_ID, TO_CHAR(EVENT_TIME, 'YYYY-MM-DD HH24:MI:SS') AS EVENT_TIME, 
                               DBA_USER, OPERATION AS ACTION_TYPE, TARGET_DEVELOPER AS TARGET_USER, 
                               DETAILS, RESULT AS STATUS, CLIENT_IP
                        FROM ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG
                        ORDER BY EVENT_TIME DESC";
                $auditLogs = $db->query($sql);
            }
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la lecture du journal d'audit : " . $e->getMessage());
        }

        return $this->render('audit.index', [
            'auditLogs' => $auditLogs,
            'scope' => $scope,
            'activePdb' => $activePdb,
        ]);
    }
}

