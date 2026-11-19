<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\DDLExecutorService;
use App\Services\DeveloperService;
use App\Services\OracleDiscoveryService;

/**
 * Contrôleur du Moteur DDL Contrôlé et Scripts SQL/PLSQL (Section 12 & 18)
 */
class ScriptController extends Controller
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
        $discovery = new OracleDiscoveryService($db);
        $pdbs = $discovery->discoverPdbs();

        if (empty($activePdb) && !empty($pdbs)) {
            foreach ($pdbs as $p) {
                if ($p['OPEN_MODE'] === 'READ WRITE') {
                    $activePdb = $p['NAME'];
                    $this->session->set('current_pdb', $activePdb);
                    break;
                }
            }
        }

        $history = [];
        $developers = [];
        $schemas = [];

        if (!empty($activePdb)) {
            $ddlService = new DDLExecutorService($db);
            $history = $ddlService->getExecutionHistory($activePdb);

            $devService = new DeveloperService($db);
            $developers = $devService->getAllDevelopers('', 'ACTIVE');
            $schemas = $discovery->discoverApplicationSchemas();
        }

        return $this->render('scripts.index', [
            'history' => $history,
            'developers' => $developers,
            'schemas' => $schemas,
            'activePdb' => $activePdb,
            'pdbs' => $pdbs,
        ]);
    }

    /**
     * Traite l'exécution d'un ordre DDL contrôlé
     */
    public function execute(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $activePdb = $this->session->get('current_pdb');
        if (empty($activePdb)) {
            $this->flash('danger', "Veuillez d'abord sélectionner une PDB active.");
            return $this->redirect('/databases');
        }

        $developer = (string)$request->post('developer_username');
        $schema = (string)$request->post('target_schema');
        $operation = (string)$request->post('operation');
        $objectType = (string)$request->post('object_type');
        $objectName = (string)$request->post('object_name');
        $ddlStatement = (string)$request->post('ddl_statement');

        if (empty($developer) || empty($schema) || empty($objectName) || empty($ddlStatement)) {
            $this->flash('danger', "Veuillez renseigner tous les champs obligatoires du formulaire DDL.");
            return $this->redirect('/scripts');
        }

        $ddlService = new DDLExecutorService($db);
        $result = $ddlService->executeDdl(
            $activePdb,
            $developer,
            $schema,
            $operation,
            $objectType,
            $objectName,
            $ddlStatement
        );

        if ($result['status'] === 'SUCCESS') {
            $this->flash('success', "Instruction DDL exécutée avec succès dans le schéma {$schema}.");
        } elseif ($result['status'] === 'REJECTED') {
            $this->flash('warning', "Instruction DDL rejetée par le moteur de sécurité : " . $result['error']);
        } else {
            $this->flash('danger', "Échec d'exécution Oracle : " . $result['error']);
        }

        return $this->redirect('/scripts');
    }
}

