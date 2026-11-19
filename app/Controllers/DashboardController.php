<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\DashboardMetricsService;

/**
 * Contrôleur du Tableau de Bord Principal (Section 17)
 */
class DashboardController extends Controller
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

        $metricsService = new DashboardMetricsService($db);
        
        try {
            $metrics = $metricsService->getMetrics();
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la récupération des métriques : " . $e->getMessage());
            $metrics = [
                'total_pdbs' => 0, 'open_pdbs' => 0, 'closed_pdbs' => 0,
                'registered_devs' => 0, 'created_accounts' => 0, 'pending_accounts' => 0,
                'access_read' => 0, 'access_write' => 0, 'access_rw' => 0, 'access_ddl' => 0,
                'recent_activity' => [],
            ];
        }

        return $this->render('dashboard.index', [
            'metrics' => $metrics,
            'server' => $this->session->get('current_server'),
            'currentCdb' => $this->session->get('current_cdb', 'CDB_PROD'),
        ]);
    }
}

