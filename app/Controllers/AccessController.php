<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\AccessProvisioningService;
use App\Services\DeveloperService;
use App\Services\OracleDiscoveryService;

/**
 * Contrôleur de Gestion des Privilèges et de la Matrice DDL / DML (Section 12)
 */
class AccessController extends Controller
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

        $discovery = new OracleDiscoveryService($db);
        $pdbs = $discovery->discoverPdbs();

        // Sélection PDB explicite via paramètre ou session
        $paramPdb = trim((string)$request->query('pdb', ''));
        if (!empty($paramPdb) && zirioam_is_valid_oracle_identifier($paramPdb)) {
            $activePdb = strtoupper($paramPdb);
            $this->session->set('current_pdb', $activePdb);
        } else {
            $activePdb = $this->session->get('current_pdb');
        }

        if (empty($activePdb) && !empty($pdbs)) {
            foreach ($pdbs as $p) {
                if ($p['OPEN_MODE'] === 'READ WRITE') {
                    $activePdb = $p['NAME'];
                    $this->session->set('current_pdb', $activePdb);
                    break;
                }
            }
        }

        $matrix = [];
        $roles = [];
        $schemas = [];
        $developers = [];
        $isPdbInstalled = true;

        // Récupération de la liste des développeurs actifs (toujours depuis CDB$ROOT)
        $devService = new DeveloperService($db);
        $developers = $devService->getAllDevelopers('', 'ACTIVE');

        if (!empty($activePdb)) {
            // Vérifier si les composants ZIRIOAM_OWNER sont installés sur cette PDB
            $detector = new \App\Services\InstallationDetectorService($db);
            $pdbCheck = $detector->detectStatus($activePdb);
            $isPdbInstalled = ($pdbCheck['status'] === \App\Services\InstallationDetectorService::INSTALLED);

            $accessService = new AccessProvisioningService($db);
            $filterSchema = (string)$request->query('schema', '');
            $filterDev = (string)$request->query('developer', '');

            $matrix = $accessService->getAccessMatrix($activePdb, $filterSchema, $filterDev);
            $roles = $accessService->getRoles($activePdb);
            $schemas = $discovery->discoverApplicationSchemas();
        }

        return $this->render('access.index', [
            'matrix' => $matrix,
            'roles' => $roles,
            'schemas' => $schemas,
            'developers' => $developers,
            'activePdb' => $activePdb,
            'pdbs' => $pdbs,
            'isPdbInstalled' => $isPdbInstalled,
        ]);
    }

    /**
     * Enregistre ou met à jour une règle d'accès (Séparation DDL / DML)
     */
    public function store(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $postPdb = trim((string)$request->post('pdb', ''));
        $activePdb = (!empty($postPdb) && zirioam_is_valid_oracle_identifier($postPdb)) ? strtoupper($postPdb) : $this->session->get('current_pdb');
        if (!empty($activePdb)) {
            $this->session->set('current_pdb', $activePdb);
        }
        if (empty($activePdb)) {
            $this->flash('danger', "Veuillez d'abord sélectionner une PDB active.");
            return $this->redirect('/databases');
        }

        $username = (string)$request->post('username');
        $schema = (string)$request->post('target_schema');
        $roleId = !empty($request->post('role_id')) ? (int)$request->post('role_id') : null;
        $canCreate = (int)$request->post('can_ddl_create', 0);
        $canAlter = (int)$request->post('can_ddl_alter', 0);
        $canDrop = (int)$request->post('can_ddl_drop', 0);
        $dbaUser = $this->session->get('current_user', 'DBA');

        $accessService = new AccessProvisioningService($db);

        try {
            $accessService->setDeveloperAccess(
                $activePdb,
                $username,
                $schema,
                $roleId,
                $canCreate,
                $canAlter,
                $canDrop,
                $dbaUser
            );
            $this->flash('success', "Les autorisations DDL et DML pour {$username} sur {$schema} ont été enregistrées avec succès.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de l'enregistrement de l'accès : " . $e->getMessage());
        }

        return $this->redirect('/access');
    }

    /**
     * Révoque une règle d'accès
     */
    public function revoke(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $activePdb = $this->session->get('current_pdb');
        $accessId = (int)($params['id'] ?? 0);
        $dbaUser = $this->session->get('current_user', 'DBA');

        $accessService = new AccessProvisioningService($db);

        try {
            $accessService->revokeDeveloperAccess($activePdb, $accessId, $dbaUser);
            $this->flash('success', "L'accès a été révoqué avec succès.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la révocation : " . $e->getMessage());
        }

        return $this->redirect('/access');
    }

    /**
     * Synchronise tous les privilèges d'objets et quotas tablespace de tous les développeurs dans la PDB active
     */
    public function syncAll(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $postPdb = trim((string)$request->post('pdb', ''));
        $activePdb = (!empty($postPdb) && zirioam_is_valid_oracle_identifier($postPdb)) ? strtoupper($postPdb) : $this->session->get('current_pdb');
        if (empty($activePdb)) {
            $this->flash('danger', "Veuillez d'abord sélectionner une PDB active.");
            return $this->redirect('/access');
        }

        $accessService = new AccessProvisioningService($db);
        try {
            $res = $accessService->syncAllPdbAccessAndQuotas($activePdb);
            $this->flash('success', "Synchronisation globale réussie sur {$activePdb} : {$res['synced_roles']} rôle(s) applicatif(s) synchronisé(s), {$res['updated_devs']} développeur(s) doté(s) des quotas illimités.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la synchronisation : " . $e->getMessage());
        }

        return $this->redirect('/access');
    }
}

