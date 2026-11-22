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

        $paramPdb = trim((string)$request->query('pdb', ''));
        if (!empty($paramPdb) && zirioam_is_valid_oracle_identifier($paramPdb)) {
            $activePdb = strtoupper($paramPdb);
            $this->session->set('current_pdb', $activePdb);
        } else {
            $activePdb = $this->session->get('current_pdb');
        }

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
        $customRoleName = trim((string)$request->post('custom_role_name', ''));
        $dbaUser = $this->session->get('current_user', 'DBA');

        $accessService = new AccessProvisioningService($db);

        try {
            $accessService->createRole($activePdb, $schema, $profileType, $description, $dbaUser, $customRoleName ?: null);
            $this->flash('success', "Le rôle fonctionnel a été créé et les privilèges d'objets ont été synchronisés avec succès dans la PDB {$activePdb}.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la création du rôle : " . $e->getMessage());
        }

        return $this->redirect('/roles');
    }

    /**
     * Synchronise les privilèges sur toutes les tables et vues du schéma vers le rôle
     */
    public function sync(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $activePdb = $this->session->get('current_pdb');
        if (empty($activePdb)) {
            $this->flash('danger', "Veuillez d'abord sélectionner une PDB active.");
            return $this->redirect('/roles');
        }

        $id = (int)($params['id'] ?? 0);
        $accessService = new AccessProvisioningService($db);
        $db->switchContainer($activePdb);

        $roleRow = $db->queryOne(
            "SELECT ROLE_ID, ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = :rid",
            ['rid' => $id]
        );

        if (!$roleRow) {
            $this->flash('danger', "Rôle introuvable.");
            return $this->redirect('/roles');
        }

        try {
            $res = $accessService->syncSchemaPrivilegesToRole(
                $roleRow['TARGET_SCHEMA'],
                $roleRow['ROLE_NAME'],
                $roleRow['PROFILE_TYPE'] ?? 'APP_READ_WRITE'
            );
            $count = count($res['granted'] ?? []);
            $errCount = count($res['errors'] ?? []);
            if ($errCount > 0) {
                $this->flash('warning', "{$count} objet(s) accordé(s), {$errCount} avertissement(s) pour {$roleRow['ROLE_NAME']}.");
            } else {
                $this->flash('success', "{$count} table(s)/vue(s) du schéma {$roleRow['TARGET_SCHEMA']} synchronisée(s) avec succès sur le rôle {$roleRow['ROLE_NAME']}.");
            }
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la synchronisation : " . $e->getMessage());
        }

        return $this->redirect('/roles');
    }
}

