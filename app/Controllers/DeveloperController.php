<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\DeveloperService;

/**
 * Contrôleur de Gestion des Développeurs et Comptes Communs
 */
class DeveloperController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Liste des développeurs avec recherche et filtres
     */
    public function index(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $search = (string)$request->query('q', '');
        $status = (string)$request->query('status', '');

        try {
            $developers = $devService->getAllDevelopers($search, $status);
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la lecture des développeurs : " . $e->getMessage());
            $developers = [];
        }

        return $this->render('developers.index', [
            'developers' => $developers,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /**
     * Formulaire de création d'un développeur
     */
    public function create(Request $request): Response
    {
        return $this->render('developers.create', []);
    }

    /**
     * Enregistrement d'un nouveau développeur
     */
    public function store(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $dbaUser = $this->session->get('current_user', 'DBA');

        $username = (string)$request->post('username', '');
        $provisionOracle = $request->post('provision_oracle') === '1';
        $pwdMode = (string)$request->post('password_mode', 'auto');
        $customPassword = $pwdMode === 'manual' ? (string)$request->post('custom_password', '') : null;

        // Support Database Vault optionnel
        $vaultUser = trim((string)$request->post('vault_user', ''));
        $vaultPassword = (string)$request->post('vault_password', '');
        if (!empty($vaultUser) && !empty($vaultPassword)) {
            $this->session->set('vault_user', strtoupper($vaultUser));
            $this->session->set('vault_password', $vaultPassword);
        }

        try {
            $devId = $devService->createDeveloper([
                'username' => $username,
                'full_name' => $request->post('full_name'),
                'email' => $request->post('email'),
                'status' => $request->post('status', 'ACTIVE'),
            ], $dbaUser);

            if ($provisionOracle) {
                $tempPassword = $devService->provisionOracleAccount(
                    $username,
                    $dbaUser,
                    $customPassword,
                    !empty($vaultUser) ? $vaultUser : null,
                    !empty($vaultPassword) ? $vaultPassword : null
                );
                $this->session->set('_temp_revealed_password', $tempPassword);
                $this->flash('success', "Le développeur et son compte Oracle commun C##{$username} ont été créés avec succès (PASSWORD EXPIRE appliqué).");
            } else {
                $this->flash('success', "Le développeur a été créé avec succès dans le référentiel ZIRIOAM.");
            }

            return $this->redirect("/developers/{$devId}");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de création : " . $e->getMessage());
            return $this->redirect('/developers/create');
        }
    }

    /**
     * Fiche détaillée d'un développeur
     */
    public function show(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        $history = $devService->getDeveloperHistory($developer['USERNAME']);

        return $this->render('developers.show', [
            'developer' => $developer,
            'history' => $history,
        ]);
    }

    /**
     * Formulaire d'édition de l'identité
     */
    public function edit(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        return $this->render('developers.edit', [
            'developer' => $developer,
        ]);
    }

    /**
     * Mise à jour de l'identité
     */
    public function update(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $dbaUser = $this->session->get('current_user', 'DBA');

        try {
            $devService->updateDeveloper($id, [
                'full_name' => $request->post('full_name'),
                'email' => $request->post('email'),
                'status' => $request->post('status', 'ACTIVE'),
            ], $dbaUser);

            $this->flash('success', "Identité du développeur mise à jour avec succès.");
            return $this->redirect("/developers/{$id}");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de mise à jour : " . $e->getMessage());
            return $this->redirect("/developers/{$id}/edit");
        }
    }

    /**
     * Activation / Désactivation de l'identité
     */
    public function toggleStatus(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $newStatus = (string)$request->post('status', 'ACTIVE');
        $dbaUser = $this->session->get('current_user', 'DBA');

        try {
            $devService->toggleStatus($id, $newStatus, $dbaUser);
            $this->flash('success', "Le statut du développeur est maintenant : {$newStatus}.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors du changement de statut : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }

    /**
     * Création effective du compte commun Oracle dans la CDB (CREATE USER C##... CONTAINER = ALL)
     */
    public function provisionAccount(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        $dbaUser = $this->session->get('current_user', 'DBA');
        $pwdMode = (string)$request->post('password_mode', 'auto');
        $customPassword = $pwdMode === 'manual' ? (string)$request->post('custom_password', '') : null;

        // Support Database Vault optionnel
        $vaultUser = trim((string)$request->post('vault_user', ''));
        $vaultPassword = (string)$request->post('vault_password', '');
        if (!empty($vaultUser) && !empty($vaultPassword)) {
            $this->session->set('vault_user', strtoupper($vaultUser));
            $this->session->set('vault_password', $vaultPassword);
        }

        try {
            $tempPassword = $devService->provisionOracleAccount(
                $developer['USERNAME'],
                $dbaUser,
                $customPassword,
                !empty($vaultUser) ? $vaultUser : null,
                !empty($vaultPassword) ? $vaultPassword : null
            );

            // Stocker le mot de passe temporaire pour affichage unique dans la session
            $this->session->set('_temp_revealed_password', $tempPassword);
            $this->flash('success', "Le compte Oracle commun {$developer['ORACLE_USER']} a été créé avec succès.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de la création du compte Oracle : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }

    /**
     * Verrouillage du compte Oracle
     */
    public function lockAccount(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        $dbaUser = $this->session->get('current_user', 'DBA');
        $vaultUser = trim((string)$request->post('vault_user', ''));
        $vaultPassword = (string)$request->post('vault_password', '');
        if (!empty($vaultUser) && !empty($vaultPassword)) {
            $this->session->set('vault_user', strtoupper($vaultUser));
            $this->session->set('vault_password', $vaultPassword);
        }

        try {
            $devService->lockOracleAccount(
                $developer['USERNAME'],
                $dbaUser,
                !empty($vaultUser) ? $vaultUser : null,
                !empty($vaultPassword) ? $vaultPassword : null
            );
            $this->flash('success', "Le compte Oracle {$developer['ORACLE_USER']} a été verrouillé avec succès (accès bloqué dans toute la CDB et les PDBs).");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors du verrouillage : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }

    /**
     * Déverrouillage du compte Oracle
     */
    public function unlockAccount(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        $dbaUser = $this->session->get('current_user', 'DBA');
        $vaultUser = trim((string)$request->post('vault_user', ''));
        $vaultPassword = (string)$request->post('vault_password', '');
        if (!empty($vaultUser) && !empty($vaultPassword)) {
            $this->session->set('vault_user', strtoupper($vaultUser));
            $this->session->set('vault_password', $vaultPassword);
        }

        try {
            $devService->unlockOracleAccount(
                $developer['USERNAME'],
                $dbaUser,
                !empty($vaultUser) ? $vaultUser : null,
                !empty($vaultPassword) ? $vaultPassword : null
            );
            $this->flash('success', "Le compte Oracle {$developer['ORACLE_USER']} a été déverrouillé avec succès (accès rétabli dans toute la CDB et les PDBs).");
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors du déverrouillage : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }
}

