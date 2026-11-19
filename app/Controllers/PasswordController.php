<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\DeveloperService;
use App\Services\PasswordResetService;

/**
 * Contrôleur de Réinitialisation Sécurisée des Mots de Passe Développeurs (Section 11)
 */
class PasswordController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Déclenche la réinitialisation du mot de passe avec expiration immédiate
     */
    public function reset(Request $request, array $params): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $devService = new DeveloperService($db);
        $pwdService = new PasswordResetService($db);

        $id = (int)($params['id'] ?? 0);
        $developer = $devService->getDeveloperById($id);

        if (!$developer) {
            $this->flash('danger', "Développeur introuvable.");
            return $this->redirect('/developers');
        }

        $dbaUser = $this->session->get('current_user', 'DBA');
        $clientIp = $request->header('X-Forwarded-For') ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $pwdMode = (string)$request->post('password_mode', 'auto');
        $customPassword = $pwdMode === 'manual' ? (string)$request->post('custom_password', '') : null;

        try {
            $tempPassword = $pwdService->resetPassword($developer['USERNAME'], $dbaUser, $clientIp, $customPassword);

            // Stocker pour affichage unique dans le bandeau d'alerte sécurisé
            $this->session->set('_temp_revealed_password', $tempPassword);
            $this->flash('success', "Le mot de passe de {$developer['ORACLE_USER']} a été réinitialisé avec succès (PASSWORD EXPIRE appliqué).");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de réinitialisation du mot de passe : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }
}

