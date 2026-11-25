<?php
namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware d'Authentification du Portail Self-Service Développeur (/selfservice)
 */
class SelfServiceAuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        $session = App::getInstance()->getSession();
        $ssData = $session->get('selfservice_session');

        if (!is_array($ssData) || empty($ssData['username']) || empty($ssData['server'])) {
            $session->setFlash('warning', "Veuillez vous connecter avec vos identifiants Oracle développeur.");
            $response = new Response();
            return $response->redirect(zirioam_url('/selfservice'));
        }

        return null;
    }
}
