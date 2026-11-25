<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DeveloperPortalService;
use App\Services\ServerRegistryService;

/**
 * Contrôleur du Portail Self-Service Développeur (/selfservice)
 * Permet aux développeurs de se connecter avec leur compte Oracle,
 * choisir une PDB autorisée, et synchroniser leurs privilèges sur les nouveaux objets.
 */
class SelfServicePortalController extends Controller
{
    private ServerRegistryService $serverRegistry;
    private DeveloperPortalService $portalService;

    public function __construct()
    {
        parent::__construct();
        $this->serverRegistry = new ServerRegistryService();
        $this->portalService = new DeveloperPortalService($this->session);
    }

    /**
     * Écran 1 : Choix du serveur Oracle (servers.json) et authentification développeur
     */
    public function index(Request $request): Response
    {
        if ($this->portalService->getSessionData() !== null) {
            return $this->redirect('/selfservice/pdbs');
        }

        $servers = [];
        $errorMessage = null;
        try {
            $servers = $this->serverRegistry->getServers(true);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        return $this->render('selfservice/login', [
            'servers' => $servers,
            'errorMessage' => $errorMessage,
        ], 'selfservice_portal');
    }

    /**
     * Authentification Oracle du développeur sur le serveur sélectionné
     */
    public function connect(Request $request): Response
    {
        $serverId = trim((string)$request->post('server_id', ''));
        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');

        $server = $this->serverRegistry->getServerById($serverId);
        if (!$server) {
            $this->flash('danger', "Serveur Oracle introuvable ou inactif dans le référentiel.");
            return $this->redirect('/selfservice');
        }

        try {
            $sess = $this->portalService->authenticate($server, $username, $password);
            $this->flash('success', "Connexion établie sur {$server['server_name']} en tant que {$sess['username']}.");
            return $this->redirect('/selfservice/pdbs');
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec d'authentification Oracle : " . $e->getMessage());
            return $this->redirect('/selfservice');
        }
    }

    /**
     * Déconnexion Oracle : renvoie vers la première page de choix du serveur
     */
    public function disconnect(Request $request): Response
    {
        $this->portalService->disconnect();
        $this->flash('info', "Vous avez été déconnecté d'Oracle.");
        return $this->redirect('/selfservice');
    }

    /**
     * Menu "Bases PDB" : liste uniquement les PDBs où le développeur dispose de privilèges actifs
     */
    public function pdbs(Request $request): Response
    {
        $accessiblePdbs = [];
        $diagnosticPdbs = [];
        $notDeployedPdbs = [];
        $discoverError = null;
        $totalOpenPdbs = 0;

        try {
            $allPdbs = $this->portalService->discoverAccessiblePdbs();
            $totalOpenPdbs = count($allPdbs);
            foreach ($allPdbs as $p) {
                if (!empty($p['ACCESSIBLE'])) {
                    $accessiblePdbs[] = $p;
                } else {
                    if (empty($p['ZIRIOAM_DEPLOYED'])) {
                        $notDeployedPdbs[] = $p['PDB_NAME'] . ' (' . ($p['STATUS_NOTE'] ?? 'non déployé') . ')';
                    }
                    $diagnosticPdbs[] = $p;
                }
            }
            usort($diagnosticPdbs, static function (array $a, array $b): int {
                return (!empty($b['ZIRIOAM_DEPLOYED']) ? 1 : 0) <=> (!empty($a['ZIRIOAM_DEPLOYED']) ? 1 : 0);
            });
        } catch (\Throwable $e) {
            $discoverError = $e->getMessage();
        }

        return $this->render('selfservice/pdbs', [
            'pdbs' => $accessiblePdbs,
            'diagnosticPdbs' => $diagnosticPdbs,
            'notDeployedPdbs' => $notDeployedPdbs,
            'totalOpenPdbs' => $totalOpenPdbs,
            'discoverError' => $discoverError,
            'activeNav' => 'pdbs',
        ], 'selfservice_portal');
    }

    /**
     * Sélectionne une PDB cible dans la session self-service développeur
     */
    public function selectPdb(Request $request, array $params): Response
    {
        $pdbName = strtoupper(trim((string)($params['pdb_name'] ?? '')));
        $serviceName = trim((string)$request->post('service_name', ''));

        try {
            $this->portalService->selectPdb($pdbName, $serviceName);
            $this->flash('success', "PDB active sélectionnée : {$pdbName}. Vous pouvez maintenant ouvrir le menu « Privs » pour synchroniser ses objets.");
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        return $this->redirect('/selfservice/pdbs');
    }

    /**
     * Menu "Privs" : gestion et synchronisation des privilèges sur les nouveaux objets
     */
    public function privs(Request $request): Response
    {
        $activePdb = $this->session->get('selfservice_pdb');
        $activeService = $this->session->get('selfservice_pdb_service');
        $accessRows = [];
        $accessError = null;

        if (!empty($activePdb)) {
            try {
                $accessRows = $this->portalService->getMyAccessInPdb((string)$activePdb, $activeService ? (string)$activeService : null);
            } catch (\Throwable $e) {
                $accessError = $e->getMessage();
            }
        }

        return $this->render('selfservice/privs', [
            'activePdb' => $activePdb,
            'accessRows' => $accessRows,
            'accessError' => $accessError,
            'activeNav' => 'privs',
        ], 'selfservice_portal');
    }

    /**
     * Synchronise les privilèges sur l'ensemble des objets de la PDB sélectionnée
     */
    public function syncPdb(Request $request): Response
    {
        $activePdb = trim((string)($request->post('pdb_name', '') ?: $this->session->get('selfservice_pdb', '')));
        $activeService = trim((string)($request->post('service_name', '') ?: $this->session->get('selfservice_pdb_service', '')));

        if (empty($activePdb)) {
            $this->flash('warning', "Veuillez d'abord sélectionner une base PDB depuis le menu « Bases PDB ».");
            return $this->redirect('/selfservice/pdbs');
        }

        try {
            $res = $this->portalService->syncPrivilegesInPdb($activePdb, $activeService ?: null);
            $this->flash(
                'success',
                "Synchronisation réussie sur la PDB {$res['pdb']} : {$res['count']} privilège(s) appliqué(s) ({$res['details']})."
            );
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de synchronisation sur {$activePdb} : " . $e->getMessage());
        }

        return $this->redirect('/selfservice/privs');
    }

    /**
     * Synchronise les privilèges sur toutes les PDBs accessibles au développeur
     */
    public function syncAll(Request $request): Response
    {
        try {
            $results = $this->portalService->syncPrivilegesAllPdbs();
            if (empty($results)) {
                $this->flash('warning', "Aucune PDB accessible avec des droits ZIRIOAM n'a été détectée pour votre compte.");
                return $this->redirect('/selfservice/privs');
            }

            $successParts = [];
            $errorParts = [];
            foreach ($results as $r) {
                if (($r['status'] ?? '') === 'SUCCESS') {
                    $successParts[] = "{$r['pdb']} ({$r['count']} octroi(s))";
                } else {
                    $errorParts[] = "{$r['pdb']} ({$r['details']})";
                }
            }

            if (!empty($successParts)) {
                $this->flash(
                    'success',
                    "Synchronisation globale terminée sur " . count($successParts) . " PDB(s) : " . implode(' | ', $successParts)
                );
            }
            if (!empty($errorParts)) {
                $this->flash('warning', "Erreurs rencontrées : " . implode(' | ', $errorParts));
            }
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la synchronisation multi-PDB : " . $e->getMessage());
        }

        return $this->redirect('/selfservice/privs');
    }
}

