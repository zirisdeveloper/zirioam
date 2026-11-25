<?php
namespace Tests;

use App\Core\App;
use App\Core\Request;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AuthMiddleware;
use App\Services\AdminAuthService;

class AdminAuthTest
{
    public static function run(): void
    {
        echo "=== [TEST] Authentification Administrateur (/etc/zirioam/auth.env & config/crypto.php) ===\n";

        require_once ZIRIOAM_ROOT_PATH . '/config/crypto.php';

        // 1. Test chiffrement déterministe et cohérence avec config/auth.env (SOND0509 / sond0509)
        $enc1 = zirioam_encrypt_admin_password('sond0509');
        $enc2 = zirioam_encrypt_admin_password('sond0509');
        assert($enc1 !== '' && $enc1 === $enc2, "Le chiffrement doit être déterministe");

        $defaultAuthService = new AdminAuthService(ZIRIOAM_ROOT_PATH . '/config/auth.env');
        $authResult = $defaultAuthService->authenticate('SOND0509', 'sond0509');
        assert($authResult['username'] === 'SOND0509', "SOND0509 doit pouvoir se connecter avec sond0509");

        // Case-insensitivity du username (sond0509 -> SOND0509), mais mot de passe strictement sensible à la casse
        $authLower = $defaultAuthService->authenticate('sond0509', 'sond0509');
        assert($authLower['username'] === 'SOND0509');

        $rejected = false;
        try {
            $defaultAuthService->authenticate('SOND0509', 'SOND0509'); // mauvaise casse mot de passe
        } catch (\InvalidArgumentException $e) {
            $rejected = true;
        }
        assert($rejected === true, "Un mot de passe incorrect doit être rejeté");
        echo "  [PASS] Chiffrement config/crypto.php et authentification SOND0509 validés.\n";

        // 2. Test cycle complet CRUD administrateurs sur fichier temporaire
        $tempAuthFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zirioam_test_auth_' . uniqid() . '.env';
        copy(ZIRIOAM_ROOT_PATH . '/config/auth.env', $tempAuthFile);

        try {
            $service = new AdminAuthService($tempAuthFile);

            // Création d'un nouvel administrateur
            $created = $service->createAdmin('admin02', 'PassAdmin#2', 'PassAdmin#2');
            assert($created === 'ADMIN02');
            assert($service->authenticate('ADMIN02', 'PassAdmin#2')['username'] === 'ADMIN02');

            // Rejet doublon
            $dupBlocked = false;
            try {
                $service->createAdmin('ADMIN02', 'OtherPass12', 'OtherPass12');
            } catch (\InvalidArgumentException $e) {
                $dupBlocked = true;
            }
            assert($dupBlocked === true, "La création d'un administrateur en doublon doit être bloquée");

            // Modification de son propre mot de passe
            $service->changeOwnPassword('SOND0509', 'sond0509', 'NewSondPass2026', 'NewSondPass2026');
            assert($service->authenticate('SOND0509', 'NewSondPass2026')['username'] === 'SOND0509');

            // Réinitialisation du mot de passe d'un autre administrateur
            $service->resetAdminPassword('ADMIN02', 'ResetPass99', 'ResetPass99');
            assert($service->authenticate('ADMIN02', 'ResetPass99')['username'] === 'ADMIN02');

            $list = $service->listAdminUsernames();
            assert(in_array('SOND0509', $list, true) && in_array('ADMIN02', $list, true));
            echo "  [PASS] Modification mot de passe, création administrateur et réinitialisation validées.\n";
        } finally {
            @unlink($tempAuthFile);
        }

        // 3. Test Middlewares (Protection plateforme vs accès public /UpdPwd)
        $session = App::getInstance()->getSession();
        $session->clear();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/servers';
        $req = new Request();

        $adminMiddleware = new AdminAuthMiddleware();
        $res1 = $adminMiddleware->handle($req);
        assert($res1 !== null && $res1->getStatusCode() === 302, "Accès à /servers sans admin_user doit rediriger vers /login");

        $authMiddleware = new AuthMiddleware();
        $res2 = $authMiddleware->handle($req);
        assert($res2 !== null && $res2->getStatusCode() === 302, "Accès à /dashboard sans admin_user doit rediriger vers /login");

        // Simuler admin connecté mais pas encore de session Oracle
        $session->set('admin_user', 'SOND0509');
        assert($adminMiddleware->handle($req) === null, "Admin connecté doit pouvoir accéder à /servers");
        $res3 = $authMiddleware->handle($req);
        assert($res3 !== null && $res3->getStatusCode() === 302, "Admin sans session Oracle doit être redirigé vers /servers");
        $session->clear();
        echo "  [PASS] Isolation AdminAuthMiddleware et AuthMiddleware validée.\n";
    }
}
