<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServerRegistryService;
use App\Services\SelfServicePasswordService;

/**
 * Contrôleur de l'Espace Développeur Autonome (Self-Service)
 * Route : /UpdPwd (sans menu d'administration, réservé aux développeurs)
 */
class DeveloperSelfServiceController extends Controller
{
    private ServerRegistryService $serverRegistry;
    private SelfServicePasswordService $pwdService;

    public function __construct()
    {
        parent::__construct();
        $this->serverRegistry = new ServerRegistryService();
        $this->pwdService = new SelfServicePasswordService($this->serverRegistry);
    }

    /**
     * Affiche l'écran autonome de changement de mot de passe
     */
    public function index(Request $request): Response
    {
        try {
            $servers = $this->serverRegistry->getServers(true);
        } catch (\Throwable $e) {
            $servers = [];
            $this->flash('danger', "Impossible de charger la liste des serveurs : " . $e->getMessage());
        }

        return $this->render('developers/self_service_password', [
            'servers' => $servers,
            'title' => 'ZIRI OAM — Espace Développeur (Changement de mot de passe)',
        ], 'self_service');
    }

    /**
     * Traite la mise à jour globale du mot de passe
     */
    public function update(Request $request): Response
    {
        $serverId = trim((string)$request->post('server_id', ''));
        $username = trim((string)$request->post('username', ''));
        $oldPassword = (string)$request->post('old_password', '');
        $newPassword = (string)$request->post('new_password', '');
        $confirmPassword = (string)$request->post('confirm_password', '');
        $adminUser = trim((string)$request->post('admin_user', ''));
        $adminPassword = (string)$request->post('admin_password', '');
        $clientIp = $request->header('X-Forwarded-For') ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        try {
            $result = $this->pwdService->changePassword(
                $serverId,
                $username,
                $oldPassword,
                $newPassword,
                $confirmPassword,
                $clientIp,
                !empty($adminUser) ? $adminUser : null,
                !empty($adminPassword) ? $adminPassword : null
            );

            $this->flash('success', "Votre mot de passe pour {$result['oracle_user']} a été mis à jour avec succès sur l'ensemble des conteneurs de la base {$result['server_name']}. Vous pouvez dès à présent vous connecter avec votre nouveau mot de passe.");
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de modification du mot de passe : " . $e->getMessage());
        }

        return $this->redirect('/UpdPwd');
    }
}

