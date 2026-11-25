<?php
namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware d'Authentification Administrateur ZIRI OAM
 * Réserve l'accès à la plateforme aux administrateurs authentifiés (/etc/zirioam/auth.env)
 */
class AdminAuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        $session = App::getInstance()->getSession();

        if (!$session->has('admin_user') || empty($session->get('admin_user'))) {
            if (strtolower((string)$request->header('X-Requested-With', '')) === 'xmlhttprequest') {
                $response = new Response();
                return $response->json([
                    'success' => false,
                    'message' => "Session administrateur expirée. Veuillez vous reconnecter.",
                ], 401);
            }

            $session->setFlash('warning', "Accès réservé aux administrateurs. Veuillez vous connecter.");
            $response = new Response();
            return $response->redirect(zirioam_url('/login'));
        }

        return null;
    }
}
