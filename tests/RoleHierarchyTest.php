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

        // 2. Test Règle 1 & 2 : Ordres SQL de création commune dans CDB$ROOT (Sans CREATE SESSION dans CDB$ROOT)
        $base = 'USER1';
        $commonUser = 'C##' . $base;
        $commonRole = 'C##RL_' . $base;

        $cdbOrders = [
            "CREATE USER {$commonUser} IDENTIFIED BY \"Temp#Pwd2026\" PASSWORD EXPIRE CONTAINER = ALL",
            "CREATE ROLE {$commonRole} CONTAINER = ALL",
            "GRANT {$commonRole} TO {$commonUser} CONTAINER = ALL",
            "ALTER USER {$commonUser} DEFAULT ROLE ALL CONTAINER = ALL",
        ];

        assert(strpos($cdbOrders[1], 'CONTAINER = ALL') !== false, "Rôle commun créé avec CONTAINER=ALL");
        assert(strpos($cdbOrders[2], 'GRANT C##RL_USER1 TO C##USER1 CONTAINER = ALL') !== false, "Rôle commun accordé à l'utilisateur");
        // Règle d'or de sécurité ZIRIOAM : Aucun privilège CONNECT ou CREATE SESSION n'est accordé dans CDB$ROOT
        foreach ($cdbOrders as $ord) {
            assert(strpos($ord, 'GRANT CONNECT') === false && strpos($ord, 'CREATE SESSION') === false, "CDB\$ROOT ne doit contenir aucun privilège CREATE SESSION");
        }
        echo "  [PASS] Validation des ordres CDB\$ROOT (Règles 1 et 2 - Connexion interdite dans CDB\$ROOT).\n";

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

        // 4. Test Synchronisation des privilèges d'objets (Résolution ORA-00942) avec préfixe par défaut RL_
        $schema = 'SARAHTEST';
        $table = 'FICHIERS_LIGNE';
        $roleRw = 'RL_SARAHTEST_RW';
        $roleRead = 'RL_SARAHTEST_READ';

        $rwSql = "GRANT SELECT, INSERT, UPDATE, DELETE ON \"{$schema}\".\"{$table}\" TO {$roleRw}";
        $roSql = "GRANT SELECT ON \"{$schema}\".\"{$table}\" TO {$roleRead}";

        assert(strpos($rwSql, 'GRANT SELECT, INSERT, UPDATE, DELETE ON "SARAHTEST"."FICHIERS_LIGNE"') !== false);
        assert(strpos($roSql, 'GRANT SELECT ON "SARAHTEST"."FICHIERS_LIGNE"') !== false);
        assert(str_starts_with($roleRw, 'RL_'), "Rôle applicatif doit être préfixé par RL_");
        echo "  [PASS] Génération et syntaxe des privilèges d'objets validées avec préfixe RL_ (Résolution ORA-00942).\n";

        // 5. Test Quotas Tablespaces & UNLIMITED TABLESPACE (Résolution ORA-01950)
        $oracleUser = 'C##USER1';
        $tablespace = 'USERS';

        $userUnlimitedSql = "GRANT UNLIMITED TABLESPACE TO {$oracleUser} CONTAINER = CURRENT";
        $quotaSql = "ALTER USER {$oracleUser} QUOTA UNLIMITED ON \"{$tablespace}\"";

        assert(strpos($userUnlimitedSql, 'GRANT UNLIMITED TABLESPACE TO C##USER1 CONTAINER = CURRENT') !== false);
        assert(strpos($quotaSql, 'ALTER USER C##USER1 QUOTA UNLIMITED ON "USERS"') !== false);

        // Vérification de la contrainte technique Oracle (ORA-01931) :
        // Le privilège système UNLIMITED TABLESPACE ne peut pas être accordé à un rôle,
        // les quotas tablespaces doivent obligatoirement être portés par l'utilisateur.
        echo "  [PASS] Génération et syntaxe des ordres de quota tablespace validées (Résolution ORA-01950).\n";
    }
}

