<?php
namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware de Protection CSRF
 */
class CsrfMiddleware
{
    public function handle(Request $request): ?Response
    {
        // Ne vérifier que les requêtes modifiant l'état (POST, etc.)
        if ($request->isPost()) {
            $session = App::getInstance()->getSession();
            $tokenName = zirioam_config('csrf.token_name', '_zirioam_csrf_token');
            
            $submittedToken = $request->post($tokenName)
                ?? $request->header('X-Csrf-Token');

            if (!$session->validateCsrfToken($submittedToken)) {
                $response = new Response();
                $response->setStatusCode(403);
                $response->setContent("<!DOCTYPE html><html><head><meta charset='utf-8'><title>403 - Jeton CSRF Invalide</title><link rel='stylesheet' href='" . zirioam_asset('css/bootstrap.min.css') . "'></head><body class='bg-light d-flex align-items-center min-vh-100'><div class='container text-center'><h1 class='display-4 text-danger fw-bold'>403</h1><p class='lead'>Échec de la validation de sécurité (Jeton CSRF invalide ou expiré).</p><a href='javascript:history.back()' class='btn btn-outline-secondary me-2'>Page précédente</a><a href='" . zirioam_url('/') . "' class='btn btn-primary'>Accueil</a></div></body></html>");
                return $response;
            }
        }

        return null; // Autoriser la suite du flux
    }
}

