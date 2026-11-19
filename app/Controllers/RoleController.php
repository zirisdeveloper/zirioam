<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\AccessProvisioningService;
use App\Services\OracleDiscoveryService;

/**
 * Contrôleur de Gestion des Rôles Fonctionnels Oracle (Section 13)
 */
class RoleController extends Controller
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

        // Si aucune PDB active, prendre la première ouverte
        if (empty($activePdb) && !empty($pdbs)) {
            foreach ($pdbs as $p) {
                if ($p['OPEN_MODE'] === 'READ WRITE') {
                    $activePdb = $p['NAME'];
                    $this->session->set('current_pdb', $activePdb);
                    break;
                }
            }
        }

        $roles = [];
        $schemas = [];
        if (!empty($activePdb)) {
            $accessService = new AccessProvisioningService($db);
            $roles = $accessService->getRoles($activePdb);
            $schemas = $discovery->discoverApplicationSchemas();
        }

        return $this->render('roles.index', [
            'roles' => $roles,
            'schemas' => $schemas,
            'activePdb' => $activePdb,
            'pdbs' => $pdbs,
        ]);
    }

    public function store(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $activePdb = $this->session->get('current_pdb');
        if (empty($activePdb)) {
            $this->flash('danger', "Veuillez d'abord sélectionner une PDB active.");
            return $this->redirect('/databases');
        }

        $schema = (string)$request->post('target_schema');
        $profileType = (string)$request->post('profile_type');
        $description = (string)$request->post('description');
        $dbaUser = $this->session->get('current_user', 'DBA');

        $accessService = new AccessProvisioningService($db);

        try {
            $accessService->createRole($activePdb, $schema, $profileType, $description, $dbaUser);
            $this->flash('success', "Le rôle fonctionnel a été créé avec succès dans la PDB {$activePdb}.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la création du rôle : " . $e->getMessage());
        }

        return $this->redirect('/roles');
    }
}

