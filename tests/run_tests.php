<?php
/**
 * ZIRI OAM — Exécuteur de Tests Unitaires et Fonctionnels
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_BAIL, 1);

define('ZIRIOAM_ROOT_PATH', dirname(__DIR__));

// Chargement des helpers en premier
require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/SecurityHelper.php';
require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/UrlHelper.php';

// Autoloader
spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'App\\')) {
        $file = ZIRIOAM_ROOT_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($file)) require_once $file;
    } elseif (str_starts_with($class, 'Tests\\')) {
        $file = ZIRIOAM_ROOT_PATH . '/tests/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (file_exists($file)) require_once $file;
    }
});

require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/UrlHelper.php';
require_once ZIRIOAM_ROOT_PATH . '/app/Helpers/SecurityHelper.php';

// Initialisation minimale d'App pour les tests
\App\Core\App::initialize([
    'servers_json_path' => ZIRIOAM_ROOT_PATH . '/config/servers.json',
    'dep_path' => '/zirioam',
    'session' => ['lifetime' => 3600],
]);

echo "\n======================================================\n";
echo "   SUITE DE TESTS OFFICIELLE ZIRI OAM (ORACLE 19c)\n";
echo "======================================================\n\n";

try {
    \Tests\ServerRegistryTest::run();
    echo "\n";
    \Tests\DeveloperImportTest::run();
    echo "\n";
    \Tests\SecurityValidatorTest::run();
    echo "\n";
    \Tests\RoleHierarchyTest::run();
    echo "\n";
    \Tests\DashboardMetricsTest::run();
    echo "\n";
    echo "======================================================\n";
    echo "   TOUS LES TESTS ONT ÉTÉ EXÉCUTÉS AVEC SUCCÈS (100%)\n";
    echo "======================================================\n\n";
} catch (\Throwable $e) {
    echo "\n[ÉCHEC DU TEST] : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . " (Ligne " . $e->getLine() . ")\n\n";
    exit(1);
}
