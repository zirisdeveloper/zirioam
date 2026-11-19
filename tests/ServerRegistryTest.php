<?php
namespace Tests;

use App\Services\ServerRegistryService;
use App\Security\Validator;

class ServerRegistryTest
{
    public static function run(): void
    {
        echo "=== [TEST] Référentiel des Serveurs (servers.json) ===\n";

        // 1. Test chargement normal
        $service = new ServerRegistryService(__DIR__ . '/../config/servers.json');
        $servers = $service->getServers(true);
        assert(count($servers) >= 2, "Au moins 2 serveurs actifs attendus.");
        echo "  [PASS] Chargement normal des serveurs réussie (" . count($servers) . " actifs).\n";

        // 2. Test recherche par ID
        $firstServerId = $servers[0]['server_id'];
        $firstServerName = $servers[0]['server_name'];
        $found = $service->getServerById($firstServerId);
        assert($found !== null, "Le serveur {$firstServerId} doit être trouvé.");
        assert($found['server_name'] === $firstServerName, "Nom serveur attendu {$firstServerName}.");
        echo "  [PASS] Recherche par server_id valide ({$firstServerId}).\n";

        // 3. Test protection anti-SSRF / serveur inconnu
        $unknown = $service->getServerById('MALICIOUS_SERVER_ID');
        assert($unknown === null, "Serveur inconnu doit retourner null.");
        echo "  [PASS] Rejet sécurisé de serveur inconnu (Anti-SSRF).\n";

        // 4. Test validation JSON invalide
        $invalidJson = ['servers' => [['server_id' => 'BAD', 'port' => 999999]]];
        $errors = Validator::validateServersJson($invalidJson);
        assert(!empty($errors), "Des erreurs doivent être détectées sur port hors limites.");
        echo "  [PASS] Validation stricte des ports et champs obligatoires.\n";
    }
}

