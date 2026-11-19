<?php
/**
 * ZIRI OAM — Oracle Developer Access Manager
 * Contrôleur Frontal (Front Controller)
 */

declare(strict_types=1);

// Configuration PHP de base
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

define('ZIRIOAM_START_TIME', microtime(true));
define('ZIRIOAM_ROOT_PATH', dirname(__DIR__));

// Autoloader PSR-4 natif (garantit le fonctionnement même sans exécution préalable de `composer dump-autoload`)
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = ZIRIOAM_ROOT_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Chargement des helpers globaux
require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/UrlHelper.php';
require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/SecurityHelper.php';

// Chargement de la configuration
$configFile = ZIRIOAM_ROOT_PATH . '/config/app.php';
$config = file_exists($configFile) ? require $configFile : [];

// Initialisation du noyau applicatif
$app = \App\Core\App::initialize($config);
$router = $app->getRouter();

// Middlewares
$csrf = \App\Middleware\CsrfMiddleware::class;
$auth = \App\Middleware\AuthMiddleware::class;

// Définition des Routes HTTP
// -------------------------------------------------------------
// Accueil & Sélection Serveur
$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->get('/servers', [\App\Controllers\ServerController::class, 'index']);

// Authentification Oracle DBA
$router->get('/servers/{server_id}/connect', [\App\Controllers\ConnectionController::class, 'show']);
$router->post('/servers/{server_id}/connect', [\App\Controllers\ConnectionController::class, 'connect'], [$csrf]);
$router->post('/disconnect', [\App\Controllers\ConnectionController::class, 'disconnect'], [$csrf]);

// Tableau de Bord Principal (Section 17)
$router->get('/dashboard', [\App\Controllers\DashboardController::class, 'index'], [$auth]);

// Découverte CDB et PDB
$router->get('/databases', [\App\Controllers\DatabaseController::class, 'index'], [$auth]);
$router->post('/databases/{pdb_name}/select', [\App\Controllers\DatabaseController::class, 'selectPdb'], [$auth, $csrf]);

// Assistant d'Installation & Statut ZIRIOAM (Phase 3)
$router->get('/installer/status', [\App\Controllers\InstallerController::class, 'status'], [$auth]);
$router->get('/installer/wizard', [\App\Controllers\InstallerController::class, 'wizard'], [$auth]);
$router->post('/installer/execute', [\App\Controllers\InstallerController::class, 'execute'], [$auth, $csrf]);
$router->post('/installer/repair', [\App\Controllers\InstallerController::class, 'repair'], [$auth, $csrf]);

// Gestion des Développeurs, Comptes Communs & Mots de Passe (Phase 4)
$router->get('/developers', [\App\Controllers\DeveloperController::class, 'index'], [$auth]);
$router->get('/developers/create', [\App\Controllers\DeveloperController::class, 'create'], [$auth]);
$router->post('/developers', [\App\Controllers\DeveloperController::class, 'store'], [$auth, $csrf]);

// Import / Export JSON ADD ONLY (Section 14)
$router->get('/developers/export', [\App\Controllers\DeveloperImportExportController::class, 'export'], [$auth]);
$router->get('/developers/import', [\App\Controllers\DeveloperImportExportController::class, 'showImport'], [$auth]);
$router->post('/developers/import/preview', [\App\Controllers\DeveloperImportExportController::class, 'previewImport'], [$auth, $csrf]);
$router->post('/developers/import/confirm', [\App\Controllers\DeveloperImportExportController::class, 'confirmImport'], [$auth, $csrf]);

// Fiche Développeur, Édition, Comptes Communs & Mots de Passe
$router->get('/developers/{id}', [\App\Controllers\DeveloperController::class, 'show'], [$auth]);
$router->get('/developers/{id}/edit', [\App\Controllers\DeveloperController::class, 'edit'], [$auth]);
$router->post('/developers/{id}', [\App\Controllers\DeveloperController::class, 'update'], [$auth, $csrf]);
$router->post('/developers/{id}/status', [\App\Controllers\DeveloperController::class, 'toggleStatus'], [$auth, $csrf]);
$router->post('/developers/{id}/provision', [\App\Controllers\DeveloperController::class, 'provisionAccount'], [$auth, $csrf]);
$router->post('/developers/{id}/password-reset', [\App\Controllers\PasswordController::class, 'reset'], [$auth, $csrf]);
$router->post('/developers/{id}/lock', [\App\Controllers\DeveloperController::class, 'lockAccount'], [$auth, $csrf]);
$router->post('/developers/{id}/unlock', [\App\Controllers\DeveloperController::class, 'unlockAccount'], [$auth, $csrf]);

// Gestion des Rôles Fonctionnels Oracle (Phase 5 - Section 13)
$router->get('/roles', [\App\Controllers\RoleController::class, 'index'], [$auth]);
$router->post('/roles', [\App\Controllers\RoleController::class, 'store'], [$auth, $csrf]);

// Matrice d'Accès : Séparation DDL / DML (Phase 5 - Section 12)
$router->get('/access', [\App\Controllers\AccessController::class, 'index'], [$auth]);
$router->post('/access', [\App\Controllers\AccessController::class, 'store'], [$auth, $csrf]);
$router->post('/access/{id}/revoke', [\App\Controllers\AccessController::class, 'revoke'], [$auth, $csrf]);

// Moteur DDL Contrôlé & Scripts SQL/PLSQL (Phase 5 - Section 12 & 18)
$router->get('/scripts', [\App\Controllers\ScriptController::class, 'index'], [$auth]);
$router->post('/scripts/execute', [\App\Controllers\ScriptController::class, 'execute'], [$auth, $csrf]);

// Audit et Traçabilité (Phase 6 - Section 16)
$router->get('/audit', [\App\Controllers\AuditController::class, 'index'], [$auth]);

// Lancement de l'application
$app->run();

