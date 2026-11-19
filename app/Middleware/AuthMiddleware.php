<?php
namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware d'Authentification DBA Oracle
 * Vérifie qu'une session de connexion à un serveur Oracle est active
 */
class AuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        $session = App::getInstance()->getSession();

        if (!$session->has('oracle_session') || !$session->has('current_server')) {
            $session->setFlash('warning', "Veuillez vous connecter à un serveur Oracle pour accéder à cette interface.");
            $response = new Response();
            return $response->redirect(zirioam_url('/servers'));
        }

        return null;
    }
}

