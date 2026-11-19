<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\OracleDiscoveryService;

/**
 * Contrôleur de Découverte des Bases CDB et PDB
 */
class DatabaseController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Affiche l'écran de découverte des CDB et PDB
     */
    public function index(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) {
            return $this->redirect('/servers');
        }

        $discoveryService = new OracleDiscoveryService($db);

        try {
            $dbInfo = $discoveryService->discoverDatabaseInfo();
            $pdbs = $discoveryService->discoverPdbs();
            $containers = $discoveryService->discoverContainers();

            // Mettre à jour le nom de la CDB en session
            $this->session->set('current_cdb', $dbInfo['db_name']);

            return $this->render('databases.index', [
                'dbInfo' => $dbInfo,
                'pdbs' => $pdbs,
                'containers' => $containers,
            ]);
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la découverte Oracle : " . $e->getMessage());
            return $this->render('databases.index', [
                'dbInfo' => ['db_name' => 'ERREUR', 'is_cdb' => true, 'banner' => 'N/A', 'open_mode' => 'N/A', 'current_container' => 'N/A'],
                'pdbs' => [],
                'containers' => [],
            ]);
        }
    }

    /**
     * Sélectionne la PDB active dans la session
     */
    public function selectPdb(Request $request, array $params): Response
    {
        $pdbName = strtoupper(trim($params['pdb_name'] ?? ''));

        if (!zirioam_is_valid_oracle_identifier($pdbName)) {
            $this->flash('danger', "Nom de PDB invalide.");
            return $this->redirect('/databases');
        }

        $this->session->set('current_pdb', $pdbName);
        $this->flash('success', "PDB active changée pour : {$pdbName}");

        return $this->redirect('/developers');
    }
}

