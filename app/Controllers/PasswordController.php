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
        $expirePassword = (bool)$request->post('expire_password', false);

        // Récupération optionnelle des identifiants Vault Manager saisis dans la modal
        $vaultUser = trim((string)$request->post('vault_user', ''));
        $vaultPassword = (string)$request->post('vault_password', '');
        if (!empty($vaultUser) && !empty($vaultPassword)) {
            $this->session->set('vault_user', strtoupper($vaultUser));
            $this->session->set('vault_password', $vaultPassword);
        }

        try {
            $tempPassword = $pwdService->resetPassword(
                $developer['USERNAME'],
                $dbaUser,
                $clientIp,
                $customPassword,
                !empty($vaultUser) ? $vaultUser : null,
                !empty($vaultPassword) ? $vaultPassword : null,
                $expirePassword
            );

            // Stocker pour affichage unique dans le bandeau d'alerte sécurisé
            $this->session->set('_temp_revealed_password', $tempPassword);
            $expireMsg = $expirePassword ? ' (PASSWORD EXPIRE appliqué)' : ' (mot de passe immédiatement actif, compte déverrouillé)';
            $this->flash('success', "Le mot de passe de {$developer['ORACLE_USER']} a été réinitialisé avec succès{$expireMsg}.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de réinitialisation du mot de passe : " . $e->getMessage());
        }

        return $this->redirect("/developers/{$id}");
    }
}

