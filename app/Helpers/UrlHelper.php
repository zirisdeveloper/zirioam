<?php
/**
 * ZIRI OAM — Oracle Developer Access Manager
 * Helpers d'URL et de routage
 */

use App\Core\App;

if (!function_exists('zirioam_config')) {
    function zirioam_config(string $key, $default = null)
    {
        return App::getInstance()->getConfig($key, $default);
    }
}

if (!function_exists('zirioam_url')) {
    /**
     * Génère une URL relative ou absolue respectant le chemin de déploiement (dep-path)
     * Exemple : zirioam_url('/servers') => '/zirioam/servers'
     */
    function zirioam_url(string $path = ''): string
    {
        $depPath = rtrim(zirioam_config('dep_path', ''), '/');
        $cleanPath = '/' . ltrim($path, '/');
        
        if ($cleanPath === '/') {
            return $depPath === '' ? '/' : $depPath;
        }
        
        return ($depPath === '' ? '' : $depPath) . $cleanPath;
    }
}

if (!function_exists('zirioam_asset')) {
    /**
     * Génère l'URL d'un asset public (CSS, JS, images)
     */
    function zirioam_asset(string $path): string
    {
        return zirioam_url('/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('zirioam_csrf_token')) {
    /**
     * Renvoie le token CSRF courant
     */
    function zirioam_csrf_token(): string
    {
        return App::getInstance()->getSession()->getCsrfToken();
    }
}

if (!function_exists('zirioam_csrf_field')) {
    /**
     * Génère un champ input masqué avec le token CSRF
     */
    function zirioam_csrf_field(): string
    {
        $token = zirioam_csrf_token();
        $name = zirioam_config('csrf.token_name', '_zirioam_csrf_token');
        return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

