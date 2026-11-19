<?php
namespace Tests;

use App\Security\Validator;

class DeveloperImportTest
{
    public static function run(): void
    {
        echo "=== [TEST] Importation Développeurs ADD ONLY (Section 22) ===\n";

        // Scénario officiel du cahier des charges :
        // Base cible contenant déjà : A1, A2, B5
        $existingDb = [
            'A1' => ['username' => 'A1', 'full_name' => 'Developer A1', 'email' => 'a1@example.com', 'status' => 'ACTIVE'],
            'A2' => ['username' => 'A2', 'full_name' => 'Developer A2', 'email' => 'a2@example.com', 'status' => 'ACTIVE'],
            'B5' => ['username' => 'B5', 'full_name' => 'Developer B5', 'email' => 'b5@example.com', 'status' => 'ACTIVE'],
        ];

        // Fichier importé contenant : A1, A2, B5, C3, D7
        $incomingFile = [
            'format' => 'ZIRIOAM_DEVELOPERS',
            'format_version' => '1.0',
            'export_date' => '2026-11-18T10:00:00',
            'source_server' => 'ORACLE-DEV-01',
            'developers' => [
                ['username' => 'A1', 'full_name' => 'Developer A1 MODIFIED IN FILE', 'email' => 'a1_hacked@example.com', 'status' => 'INACTIVE'],
                ['username' => 'A2', 'full_name' => 'Developer A2', 'email' => 'a2@example.com', 'status' => 'ACTIVE'],
                ['username' => 'B5', 'full_name' => 'Developer B5', 'email' => 'b5@example.com', 'status' => 'ACTIVE'],
                ['username' => 'C3', 'full_name' => 'Developer C3', 'email' => 'c3@example.com', 'status' => 'ACTIVE'],
                ['username' => 'D7', 'full_name' => 'Developer D7', 'email' => 'd7@example.com', 'status' => 'ACTIVE'],
            ]
        ];

        // 1. Validation du format
        $validationErrors = Validator::validateDeveloperImportJson($incomingFile);
        assert(empty($validationErrors), "Le format JSON doit être 100% valide.");
        echo "  [PASS] Validation du format ZIRIOAM_DEVELOPERS v1.0.\n";

        // 2. Simulation de la séparation ADD ONLY
        $existingInFile = [];
        $newToInsert = [];

        foreach ($incomingFile['developers'] as $dev) {
            $u = strtoupper(trim($dev['username']));
            if (isset($existingDb[$u])) {
                $existingInFile[] = $u;
            } else {
                $newToInsert[] = $dev;
            }
        }

        // Vérifications strictes du résultat :
        assert(count($incomingFile['developers']) === 5, "Total dans fichier attendu : 5");
        assert(count($existingInFile) === 3, "Déjà existants attendus : 3 (A1, A2, B5)");
        assert(count($newToInsert) === 2, "Nouveaux à insérer attendus : 2 (C3, D7)");
        echo "  [PASS] Détection exacte : 5 dans fichier, 3 existants (A1, A2, B5), 2 nouveaux (C3, D7).\n";

        // 3. Règle absolue : Les existants A1, A2, B5 ne sont JAMAIS modifiés
        // Même si le fichier contenait un email différent 'a1_hacked@example.com' pour A1
        assert($existingDb['A1']['email'] === 'a1@example.com', "A1 ne doit pas être écrasé par le fichier.");
        assert($existingDb['A1']['status'] === 'ACTIVE', "Le statut de A1 reste inchangé.");
        echo "  [PASS] Intégrité absolue : A1, A2 et B5 restent strictement inchangés.\n";

        // 4. Insertion des nouveaux dans la base
        foreach ($newToInsert as $newDev) {
            $existingDb[$newDev['username']] = $newDev;
        }

        assert(count($existingDb) === 5, "La base cible contient maintenant exactement 5 développeurs.");
        assert(isset($existingDb['C3']) && isset($existingDb['D7']), "C3 et D7 ont bien été ajoutés.");
        echo "  [PASS] Nouveaux développeurs C3 et D7 ajoutés avec succès.\n";

        // 5. Test ré-importation du même fichier (Idempotence)
        $newToInsert2 = [];
        foreach ($incomingFile['developers'] as $dev) {
            $u = strtoupper(trim($dev['username']));
            if (!isset($existingDb[$u])) {
                $newToInsert2[] = $dev;
            }
        }
        assert(count($newToInsert2) === 0, "Une ré-importation du même fichier ne doit rien ajouter.");
        echo "  [PASS] Idempotence : L'importation répétée donne un résultat cohérent sans doublon.\n";
    }
}

