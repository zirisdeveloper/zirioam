<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServerRegistryService;

/**
 * Contrôleur de Sélection des Serveurs Oracle
 */
class ServerController extends Controller
{
    private ServerRegistryService $registryService;

    public function __construct()
    {
        parent::__construct();
        $this->registryService = new ServerRegistryService();
    }

    /**
     * Affiche l'écran initial de sélection des serveurs (Écran 1)
     */
    public function index(Request $request): Response
    {
        $servers = [];
        $errorMessage = null;
        $filePath = $this->registryService->getFilePath();

        try {
            $servers = $this->registryService->getServers(true);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        return $this->render('servers.index', [
            'servers' => $servers,
            'errorMessage' => $errorMessage,
            'filePath' => $filePath,
        ], 'auth');
    }
}

