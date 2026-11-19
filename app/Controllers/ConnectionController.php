<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServerRegistryService;
use App\Services\OracleConnectionService;

/**
 * Contrôleur d'Authentification et Connexion Oracle DBA
 */
class ConnectionController extends Controller
{
    private ServerRegistryService $registryService;
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->registryService = new ServerRegistryService();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Affiche le formulaire d'authentification pour un serveur donné
     */
    public function show(Request $request, array $params): Response
    {
        $serverId = $params['server_id'] ?? '';
        $server = $this->registryService->getServerById($serverId);

        if (!$server) {
            $this->flash('danger', "Serveur Oracle introuvable ou non autorisé.");
            return $this->redirect('/servers');
        }

        // Services CDB disponibles
        $cdbServices = $server['cdb_services'] ?? [];
        $defaultService = !empty($cdbServices) ? $cdbServices[0]['root_service'] : '';

        return $this->render('servers.connect', [
            'server' => $server,
            'defaultService' => $defaultService,
        ], 'auth');
    }

    /**
     * Traite la soumission des identifiants DBA
     */
    public function connect(Request $request, array $params): Response
    {
        $serverId = $params['server_id'] ?? '';
        $server = $this->registryService->getServerById($serverId);

        if (!$server) {
            $this->flash('danger', "Tentative de connexion vers un serveur non autorisé (rejet de sécurité).");
            return $this->redirect('/servers');
        }

        $username = trim((string)$request->post('username'));
        $password = (string)$request->post('password');
        $mode = strtoupper(trim((string)$request->post('mode', 'NORMAL')));
        $serviceName = trim((string)$request->post('service_name'));

        // Validation basique des champs obligatoires
        if (empty($username) || empty($password) || empty($serviceName)) {
            $this->flash('danger', "Veuillez renseigner tous les champs obligatoires (Utilisateur, Mot de passe, Service racine).");
            return $this->redirect("/servers/{$serverId}/connect");
        }

        // Tenter l'authentification Oracle
        try {
            $connection = $this->connectionService->authenticate(
                $server,
                $serviceName,
                $username,
                $password,
                $mode
            );

            $this->flash('success', "Connexion établie avec succès sur {$server['server_name']} (Mode {$mode}).");
            
            // Redirection vers le détecteur d'installation ou tableau de bord
            return $this->redirect('/installer/status');
        } catch (\Throwable $e) {
            $this->flash('danger', "Échec de connexion Oracle : " . $e->getMessage());
            return $this->redirect("/servers/{$serverId}/connect");
        }
    }

    /**
     * Déconnexion de la session Oracle
     */
    public function disconnect(Request $request): Response
    {
        $this->connectionService->disconnect();
        $this->flash('info', "Vous avez été déconnecté du serveur Oracle en toute sécurité.");
        return $this->redirect('/servers');
    }
}

