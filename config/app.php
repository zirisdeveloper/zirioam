<?php
/**
 * ZIRI OAM — Oracle Developer Access Manager
 * Configuration générale de l'application
 */

return [
    // Nom officiel de l'application
    'app_name' => 'ZIRI OAM',
    'app_title' => 'ZIRI OAM — Oracle Developer Access Manager',
    'version' => '1.0.0',

    // Environnement : 'production' ou 'development'
    'env' => getenv('ZIRIOAM_ENV') ?: 'development',
    'debug' => (bool)(getenv('ZIRIOAM_DEBUG') ?: true),

    // URL de base et chemin de déploiement Apache
    // Exemple : si l'application est servie sur http://mon-serveur/zirioam/
    // 'base_url' => 'http://mon-serveur', 'dep_path' => '/zirioam'
    'base_url' => getenv('ZIRIOAM_BASE_URL') ?: '',
    'dep_path' => getenv('ZIRIOAM_DEP_PATH') ?: '/zirioam',

    // Chemin absolu vers le fichier de référentiel des serveurs Oracle
    // STRICTEMENT HORS DU RÉPERTOIRE PUBLIC
    // En production Linux : /etc/zirioam/servers.json
    // En environnement de test / local : chemin configurable
    'servers_json_path' => getenv('ZIRIOAM_SERVERS_JSON') ?: (
        DIRECTORY_SEPARATOR === '/'
            ? '/etc/zirioam/servers.json'
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'servers.json'
    ),

    // Chemin absolu vers le fichier d'authentification des administrateurs ZIRIOAM
    // En production Linux : /etc/zirioam/auth.env
    // En environnement local / test : config/auth.env
    'auth_env_path' => getenv('ZIRIOAM_AUTH_ENV') ?: (
        DIRECTORY_SEPARATOR === '/'
            ? '/etc/zirioam/auth.env'
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auth.env'
    ),

    // Sécurité et sessions
    'session' => [
        'name' => 'ZIRIOAM_SESSID',
        'lifetime' => 3600 * 4, // 4 heures
        'path' => '/',
        'domain' => '',
        'secure' => false, // Passer à true en HTTPS de production
        'httponly' => true,
        'samesite' => 'Strict',
    ],

    'csrf' => [
        'token_name' => '_zirioam_csrf_token',
        'header_name' => 'X-CSRF-TOKEN',
    ],

    // Paramètres Oracle par défaut
    'oracle' => [
        'default_port' => 1521,
        'charset' => 'AL32UTF8',
        'common_user_prefix' => 'C##',
        'cdb_owner' => 'C##ZIRIOAM_ADMIN',
        'pdb_owner' => 'ZIRIOAM_OWNER',
        'connection_timeout' => 10,
    ],

    // Répertoires internes
    'paths' => [
        'root' => dirname(__DIR__),
        'storage' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        'logs' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
        'installer' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'installer',
        'views' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views',
    ],
];

