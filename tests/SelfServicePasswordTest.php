<?php
namespace Tests;

use App\Core\Request;
use App\Core\Router;
use App\Services\SelfServicePasswordService;
use App\Services\ServerRegistryService;

class SelfServicePasswordTest
{
    public static function run(): void
    {
        echo "=== [TEST] Espace Développeur Self-Service (/UpdPwd) ===\n";

        $serverRegistry = new ServerRegistryService(ZIRIOAM_ROOT_PATH . '/config/servers.json');
        $service = new SelfServicePasswordService($serverRegistry);

        // 1. Test Rejet de serveur inconnu
        try {
            $service->changePassword('SERVEUR_INCONNU', 'SON0196', 'OldPwd123#', 'NewPwd456#', 'NewPwd456#');
            assert(false, "Un serveur inconnu doit lever une exception");
        } catch (\InvalidArgumentException $e) {
            assert(strpos($e->getMessage(), 'introuvable') !== false);
        }
        echo "  [PASS] Rejet sécurisé de serveur non configuré.\n";

        // 2. Test Rejet de nom d'utilisateur non valide (injection SQL)
        try {
            $service->changePassword('ALGORA18', 'SON;DROP TABLE--', 'OldPwd123#', 'NewPwd456#', 'NewPwd456#');
            assert(false, "Une injection SQL dans l'identifiant doit être bloquée");
        } catch (\InvalidArgumentException $e) {
            assert(strpos($e->getMessage(), 'Identifiant') !== false);
        }
        echo "  [PASS] Rejet strict des injections SQL sur l'identifiant développeur.\n";

        // 3. Test Rejet de confirmation différente
        try {
            $service->changePassword('ALGORA18', 'SON0196', 'OldPwd123#', 'NewPwd456#', 'DifferentPwd789#');
            assert(false, "Une confirmation différente doit être rejetée");
        } catch (\InvalidArgumentException $e) {
            assert(strpos($e->getMessage(), 'ne correspondent pas') !== false);
        }
        echo "  [PASS] Rejet de confirmation de mot de passe divergente.\n";

        // 4. Test Rejet de nouveau mot de passe identique à l'ancien
        try {
            $service->changePassword('ALGORA18', 'SON0196', 'SamePassword123#', 'SamePassword123#', 'SamePassword123#');
            assert(false, "Le nouveau mot de passe identique à l'ancien doit être rejeté");
        } catch (\InvalidArgumentException $e) {
            assert(strpos($e->getMessage(), 'différent') !== false);
        }
        echo "  [PASS] Rejet du nouveau mot de passe identique à l'ancien.\n";

        // 5. Test Règles de complexité Oracle 19c
        try {
            $service->changePassword('ALGORA18', 'SON0196', 'OldPwd123#', 'court', 'court');
            assert(false, "Un mot de passe trop court doit être rejeté");
        } catch (\InvalidArgumentException $e) {
            assert(strpos($e->getMessage(), '8 caractères') !== false);
        }
        echo "  [PASS] Validation des règles de complexité Oracle (longueur minimale 8 car).\n";

        // 6. Test Routeur : insensible à la casse pour /UpdPwd, /updpwd, /UPDPWD
        $router = new Router();
        $matched = false;
        $router->get('/UpdPwd', function() use (&$matched) {
            $matched = true;
            return new \App\Core\Response();
        });

        // Test avec /updpwd
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/updpwd';
        $req1 = new Request();
        $router->dispatch($req1);
        assert($matched === true, "La route /updpwd doit matcher /UpdPwd");

        // Test avec /UpdPwd
        $matched = false;
        $_SERVER['REQUEST_URI'] = '/UpdPwd';
        $req2 = new Request();
        $router->dispatch($req2);
        assert($matched === true, "La route /UpdPwd doit matcher /UpdPwd");
        echo "  [PASS] Routage case-insensitive validé (/UpdPwd et /updpwd).\n";
    }
}
