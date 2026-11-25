<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminAuthService;
use App\Services\OracleConnectionService;

/**
 * Contrôleur d'Authentification Plateforme et Gestion du Profil Administrateur
 */
class AdminAuthController extends Controller
{
    private AdminAuthService $authService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AdminAuthService();
    }

    /**
     * Affiche la page de connexion administrateur
     */
    public function showLogin(Request $request): Response
    {
        if ($this->session->has('admin_user')) {
            if ($this->session->has('oracle_session')) {
                return $this->redirect('/databases');
            }
            return $this->redirect('/servers');
        }

        return $this->render('admin/login', [
            'authFilePath' => $this->authService->getFilePath(),
        ], 'auth');
    }

    /**
     * Traite l'authentification d'un administrateur via /etc/zirioam/auth.env
     */
    public function login(Request $request): Response
    {
        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');

        try {
            $admin = $this->authService->authenticate($username, $password);

            $this->session->regenerate(true);
            $this->session->set('admin_user', $admin['username']);
            $this->flash('success', "Bienvenue {$admin['username']}. Vous êtes connecté à l'espace d'administration ZIRI OAM.");

            return $this->redirect('/servers');
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/login');
        }
    }

    /**
     * Déconnexion complète de la plateforme administrateur (et fermeture éventuelle de la session Oracle)
     */
    public function logout(Request $request): Response
    {
        try {
            if ($this->session->has('oracle_session')) {
                (new OracleConnectionService())->disconnect();
            }
        } catch (\Throwable $e) {
            // Ignorer erreur de fermeture Oracle à la déconnexion
        }

        $this->session->remove('admin_user');
        $this->session->regenerate(true);
        $this->flash('info', "Vous avez été déconnecté de l'interface d'administration ZIRI OAM.");

        return $this->redirect('/login');
    }

    /**
     * Modification du mot de passe de l'administrateur connecté
     */
    public function changeOwnPassword(Request $request): Response
    {
        $currentAdmin = (string)$this->session->get('admin_user', '');
        $currentPassword = (string)$request->post('current_password', '');
        $newPassword = (string)$request->post('new_password', '');
        $confirmPassword = (string)$request->post('confirm_password', '');

        try {
            $this->authService->changeOwnPassword(
                $currentAdmin,
                $currentPassword,
                $newPassword,
                $confirmPassword
            );
            $this->flash('success', "Votre mot de passe administrateur ({$currentAdmin}) a été modifié avec succès.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de modification du mot de passe : " . $e->getMessage());
        }

        return $this->redirect($this->resolveReturnPath($request));
    }

    /**
     * Création d'un nouvel administrateur
     */
    public function createAdmin(Request $request): Response
    {
        $newUsername = trim((string)$request->post('new_username', ''));
        $newPassword = (string)$request->post('new_password', '');
        $confirmPassword = (string)$request->post('confirm_password', '');

        try {
            $createdUser = $this->authService->createAdmin($newUsername, $newPassword, $confirmPassword);
            $this->flash('success', "L'administrateur '{$createdUser}' a été créé avec succès dans le référentiel d'authentification.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de création de l'administrateur : " . $e->getMessage());
        }

        return $this->redirect($this->resolveReturnPath($request));
    }

    /**
     * Initialisation / réinitialisation du mot de passe d'un autre administrateur
     */
    public function resetAdminPassword(Request $request): Response
    {
        $targetUsername = trim((string)$request->post('target_username', ''));
        $newPassword = (string)$request->post('new_password', '');
        $confirmPassword = (string)$request->post('confirm_password', '');

        try {
            $updatedUser = $this->authService->resetAdminPassword($targetUsername, $newPassword, $confirmPassword);
            $this->flash('success', "Le mot de passe de l'administrateur '{$updatedUser}' a été réinitialisé avec succès.");
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de réinitialisation du mot de passe : " . $e->getMessage());
        }

        return $this->redirect($this->resolveReturnPath($request));
    }

    /**
     * Détermine une route interne sûre vers laquelle rediriger l'administrateur après action sur son profil
     */
    private function resolveReturnPath(Request $request): string
    {
        $returnTo = trim((string)$request->post('redirect_to', ''));
        $depPath = rtrim((string)zirioam_config('dep_path', ''), '/');

        if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
            if ($depPath !== '' && str_starts_with($returnTo, $depPath . '/')) {
                $returnTo = substr($returnTo, strlen($depPath));
            }
            if ($returnTo !== '' && $returnTo !== '/login') {
                return $returnTo;
            }
        }

        return $this->session->has('oracle_session') ? '/dashboard' : '/servers';
    }
}
