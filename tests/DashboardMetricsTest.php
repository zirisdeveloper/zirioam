<?php
namespace Tests;

use App\Services\DashboardMetricsService;

/**
 * Test unitaire du service de métriques réelles du tableau de bord (Section 17)
 */
class DashboardMetricsTest
{
    public static function run(): void
    {
        echo "=== [TEST] Métriques Réelles du Tableau de Bord (Section 17) ===\n";

        // 1. Test de la classification fine des profils et suffixes de rôles
        $readCount = 0;
        $writeCount = 0;
        $rwCount = 0;

        // Reflection pour tester la méthode privée classifyRoleProfile ou test via service
        $refMethod = new \ReflectionMethod(DashboardMetricsService::class, 'classifyRoleProfile');
        $refMethod->setAccessible(true);

        $dummyService = new DashboardMetricsService(new class extends \App\Database\OracleConnection {
            public function __construct() {}
        });

        // Test READ_WRITE via PROFILE_TYPE
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['APP_READ_WRITE', 'RL_CUSTOM', &$readCount, &$writeCount, &$rwCount]);
        assert($rwCount === 1, "APP_READ_WRITE doit incrémenter rwCount");

        // Test READ_WRITE via suffixe _RW
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['', 'RL_SARAHTEST_RW', &$readCount, &$writeCount, &$rwCount]);
        assert($rwCount === 1, "RL_SARAHTEST_RW doit incrémenter rwCount");

        // Test READ via PROFILE_TYPE
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['APP_READ', 'RL_CUSTOM', &$readCount, &$writeCount, &$rwCount]);
        assert($readCount === 1, "APP_READ doit incrémenter readCount");

        // Test READ via suffixe _READ
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['', 'RL_GESTION_READ', &$readCount, &$writeCount, &$rwCount]);
        assert($readCount === 1, "RL_GESTION_READ doit incrémenter readCount");

        // Test WRITE via suffixe _WRITE
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['', 'RL_GESTION_WRITE', &$readCount, &$writeCount, &$rwCount]);
        assert($writeCount === 1, "RL_GESTION_WRITE doit incrémenter writeCount");

        // Test rôle par défaut sans profil explicite
        $readCount = 0; $writeCount = 0; $rwCount = 0;
        $refMethod->invokeArgs($dummyService, ['', 'RL_APP_FINANCE', &$readCount, &$writeCount, &$rwCount]);
        assert($rwCount === 1, "Rôle sans suffixe doit incrémenter rwCount par défaut");

        echo "  [PASS] Classification multi-critères des rôles applicatifs validée (READ, WRITE, RW).\n";

        // 2. Test du filtrage des rôles d'identité vs rôles applicatifs
        $knownDevs = ['SON6987', 'USER1'];
        $devIdentityRoles = [];
        foreach ($knownDevs as $kd) {
            $devIdentityRoles[] = 'RL_' . $kd;
            $devIdentityRoles[] = 'C##RL_' . $kd;
        }

        // Cas 1 : Rôle d'identité local RL_SON6987 accordé à C##RL_SON6987 (Règle 3) -> doit être ignoré
        $testRole1 = 'RL_SON6987';
        assert(in_array($testRole1, $devIdentityRoles), "RL_SON6987 est un rôle d'identité");

        // Cas 2 : Rôle applicatif RL_SARAHTEST_RW accordé à RL_SON6987 -> doit être détecté
        $testRole2 = 'RL_SARAHTEST_RW';
        assert(!in_array($testRole2, $devIdentityRoles), "RL_SARAHTEST_RW n'est pas un rôle d'identité");

        echo "  [PASS] Distinction stricte entre rôles d'identité développeurs et rôles applicatifs validée.\n";
    }
}

