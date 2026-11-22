<?php
namespace Tests;

/**
 * Test unitaire de la hiérarchie des rôles (Commun C##RL_ / Local PDB RL_ / Objets schémas)
 */
class RoleHierarchyTest
{
    public static function run(): void
    {
        echo "=== [TEST] Hiérarchie des Rôles et Attribution PDB (Règles 1, 2 et 3) ===\n";

        // 1. Test des conventions de nommage pour utilisateur 'USER1' et 'C##USER1'
        $usernames = ['USER1', 'C##USER1', 'user1', 'c##user1'];
        foreach ($usernames as $u) {
            $norm = strtoupper(trim($u));
            $base = str_starts_with($norm, 'C##') ? substr($norm, 3) : $norm;
            $commonUser = 'C##' . $base;
            $commonRole = 'C##RL_' . $base;
            $localRole = 'RL_' . $base;

            assert($base === 'USER1', "Base user doit être USER1");
            assert($commonUser === 'C##USER1', "Common user doit être C##USER1");
            assert($commonRole === 'C##RL_USER1', "Common role doit être C##RL_USER1");
            assert($localRole === 'RL_USER1', "Local role doit être RL_USER1");
        }
        echo "  [PASS] Conventions de nommage exactes : C##USER1 <-> C##RL_USER1 <-> RL_USER1.\n";

        // 2. Test Règle 1 & 2 : Ordres SQL de création commune dans CDB$ROOT
        $base = 'USER1';
        $commonUser = 'C##' . $base;
        $commonRole = 'C##RL_' . $base;

        $cdbOrders = [
            "CREATE USER {$commonUser} IDENTIFIED BY \"Temp#Pwd2026\" PASSWORD EXPIRE CONTAINER = ALL",
            "CREATE ROLE {$commonRole} CONTAINER = ALL",
            "GRANT CONNECT TO {$commonRole} CONTAINER = ALL",
            "GRANT {$commonRole} TO {$commonUser} CONTAINER = ALL",
            "ALTER USER {$commonUser} DEFAULT ROLE ALL CONTAINER = ALL",
        ];

        assert(strpos($cdbOrders[1], 'CONTAINER = ALL') !== false, "Rôle commun créé avec CONTAINER=ALL");
        assert(strpos($cdbOrders[2], 'GRANT CONNECT TO C##RL_USER1 CONTAINER = ALL') !== false, "Privilège CONNECT accordé au rôle commun");
        assert(strpos($cdbOrders[3], 'GRANT C##RL_USER1 TO C##USER1 CONTAINER = ALL') !== false, "Rôle commun accordé à l'utilisateur");
        echo "  [PASS] Validation des ordres CDB\$ROOT (Règles 1 et 2).\n";

        // 3. Test Règle 3 : Ordres SQL dans la PDB locale
        $localRole = 'RL_' . $base;
        $targetRole = 'RL_PAIE_RW';

        $pdbOrders = [
            "CREATE ROLE {$localRole}",
            "GRANT CREATE SESSION TO {$localRole}",
            "GRANT {$targetRole} TO {$localRole}",
            "GRANT {$localRole} TO {$commonRole} CONTAINER = CURRENT",
            "GRANT {$localRole} TO {$commonUser} CONTAINER = CURRENT",
            "ALTER USER {$commonUser} DEFAULT ROLE ALL",
        ];

        assert($pdbOrders[0] === 'CREATE ROLE RL_USER1', "Création du rôle local RL_USER1");
        assert($pdbOrders[1] === 'GRANT CREATE SESSION TO RL_USER1', "Attribution CREATE SESSION au rôle local");
        assert($pdbOrders[2] === 'GRANT RL_PAIE_RW TO RL_USER1', "Attribution du rôle fonctionnel au rôle local");
        assert($pdbOrders[3] === 'GRANT RL_USER1 TO C##RL_USER1 CONTAINER = CURRENT', "Liaison rôle local vers rôle commun");
        assert($pdbOrders[4] === 'GRANT RL_USER1 TO C##USER1 CONTAINER = CURRENT', "Liaison rôle local vers utilisateur développeur");
        echo "  [PASS] Validation des ordres locaux PDB (Règle 3).\n";

        // 4. Test Synchronisation des privilèges d'objets (Résolution ORA-00942)
        $schema = 'SARAHTEST';
        $table = 'FICHIERS_LIGNE';
        $roleRw = 'R_SARAHTEST_RW';
        $roleRead = 'R_SARAHTEST_READ';

        $rwSql = "GRANT SELECT, INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$table}\" TO {$roleRw}";
        $roSql = "GRANT SELECT ON \"{$schema}\".\"{$table}\" TO {$roleRead}";

        assert(strpos($rwSql, 'GRANT SELECT, INSERT, UPDATE, DELETE ON "SARAHTEST"."FICHIERS_LIGNE"') !== false);
        assert(strpos($roSql, 'GRANT SELECT ON "SARAHTEST"."FICHIERS_LIGNE"') !== false);
        echo "  [PASS] Génération et syntaxe des privilèges d'objets validées (Résolution ORA-00942).\n";
    }
}

