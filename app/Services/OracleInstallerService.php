<?php
namespace App\Services;

use App\Core\App;
use App\Database\OracleConnection;
use App\Database\OracleQueryException;

/**
 * Service d'Installation Automatique des Composants Oracle ZIRIOAM
 */
class OracleInstallerService
{
    private OracleConnection $db;
    private string $installerPath;
    private array $manifest;

    public function __construct(OracleConnection $db, ?string $installerPath = null)
    {
        $this->db = $db;
        $this->installerPath = $installerPath ?: (string)zirioam_config('paths.installer');
        $this->loadManifest();
    }

    private function loadManifest(): void
    {
        $manifestFile = $this->installerPath . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!file_exists($manifestFile)) {
            throw new \RuntimeException("Fichier manifest.json introuvable : {$manifestFile}");
        }

        $content = file_get_contents($manifestFile);
        $this->manifest = json_decode($content, true) ?: [];
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * Exécute l'installation des composants communs CDB$ROOT
     */
    public function installCdbComponents(?string $vaultUser = null, ?string $vaultPassword = null, callable $stepCallback = null): array
    {
        $scripts = $this->manifest['common_scripts'] ?? [];
        $results = [];

        // S'assurer d'être sur CDB$ROOT
        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        $hasVaultStep = (!empty($vaultUser) && !empty($vaultPassword));
        $totalSteps = count($scripts) + ($hasVaultStep ? 1 : 0);

        foreach ($scripts as $idx => $scriptMeta) {
            $stepNum = $idx + 1;
            $progressPercent = (int)round(($stepNum / $totalSteps) * 100);

            $scriptFile = $this->installerPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $scriptMeta['file']);
            if (!file_exists($scriptFile)) {
                $err = "Fichier de script manquant : {$scriptFile}";
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'FAILED', 'error' => $err];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'FAILED', $err);
                break;
            }

            // Exécution du script
            try {
                $this->executeSqlScript($scriptFile);
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'SUCCESS', 'error' => null];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'SUCCESS', null);
            } catch (\Throwable $e) {
                $err = "Erreur Oracle lors de l'étape {$stepNum} : " . $e->getMessage();
                if (strpos($e->getMessage(), 'ORA-24344') !== false) {
                    $details = $this->fetchCompilationErrors();
                    if (!empty($details)) {
                        $err .= " [Détails: " . implode(' | ', $details) . "]";
                    }
                }
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'FAILED', 'error' => $err];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'FAILED', $err);
                break;
            }
        }

        // Si tous les scripts ont réussi et qu'un compte Vault Manager est configuré : étape d'habilitation
        $allSuccess = true;
        foreach ($results as $r) {
            if ($r['status'] !== 'SUCCESS') {
                $allSuccess = false;
                break;
            }
        }

        if ($allSuccess && $hasVaultStep) {
            $stepNum = count($scripts) + 1;
            $vaultStepName = "Attribution des privilèges Vault Manager via {$vaultUser} (ALTER USER, CREATE USER, DV_ACCTMGR)";
            try {
                $this->grantVaultManagerPrivileges($vaultUser, $vaultPassword);
                $results[] = ['step' => $stepNum, 'name' => $vaultStepName, 'status' => 'SUCCESS', 'error' => null];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, 100, $vaultStepName, 'SUCCESS', null);
            } catch (\Throwable $ve) {
                $err = "Erreur lors de l'habilitation Vault Manager ({$vaultUser}) : " . $ve->getMessage();
                $results[] = ['step' => $stepNum, 'name' => $vaultStepName, 'status' => 'FAILED', 'error' => $err];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, 100, $vaultStepName, 'FAILED', $err);
            }
        }

        return $results;
    }

    /**
     * Se connecte en tant que Vault Manager (ex: C##VOLTMANAGER) pour attribuer les droits à C##ZIRIOAM_ADMIN
     */
    public function grantVaultManagerPrivileges(string $vaultUser, string $vaultPassword): void
    {
        $session = \App\Core\App::getInstance()->getSession();
        $server = $session->get('current_server');
        $service = $session->get('current_service');

        if (!$server || !$service) {
            throw new \RuntimeException("Données de session serveur indisponibles pour la connexion Vault Manager.");
        }

        $vaultConn = new \App\Database\OracleConnection(
            $server['ip_address'],
            (int)$server['port'],
            $service,
            $vaultUser,
            $vaultPassword,
            'NORMAL'
        );

        try {
            $currentCon = $vaultConn->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $vaultConn->switchContainer('CDB$ROOT');
            }

            // 1. Privilèges de gestion des utilisateurs communs (tolérance si Realm Command Rule active sous Database Vault)
            try {
                $vaultConn->executeDdl("GRANT ALTER USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {
                // Sous Database Vault strict, ORA-47410 interdit les délégations de GRANT sur ALTER USER.
                // Cela est normal : ZIRIOAM exécute alors les opérations utilisateurs directement via la session C##VOLTMANAGER.
            }

            try {
                $vaultConn->executeDdl("GRANT CREATE USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}

            try {
                $vaultConn->executeDdl("GRANT DROP USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // Gestion des rôles communs (C##RL_<USER>)
            try {
                $vaultConn->executeDdl("GRANT CREATE ROLE TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $vaultConn->executeDdl("GRANT GRANT ANY ROLE TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // Droits d'octroi de privilèges sur les objets applicatifs
            try {
                $vaultConn->executeDdl("GRANT GRANT ANY OBJECT PRIVILEGE TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}
            try {
                $vaultConn->executeDdl("GRANT SELECT ANY TABLE TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 2. Rôle Database Vault Account Manager (si configuré)
            try {
                $vaultConn->executeDdl("GRANT DV_ACCTMGR TO C##ZIRIOAM_ADMIN CONTAINER = ALL");
            } catch (\Throwable $e) {}

            // 3. Droit d'exécution sur le package d'orchestration
            try {
                $vaultConn->executeDdl("GRANT EXECUTE ON C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS TO PUBLIC CONTAINER = ALL");
            } catch (\Throwable $e) {}
        } finally {
            $vaultConn->close();
        }
    }

    /**
     * Réexécute les composants ZIRIOAM avec options : CREATE OR REPLACE et GRANTs
     */
    public function redeployComponents(
        string $targetContainer,
        bool $replaceObjects = true,
        bool $reapplyGrants = true,
        ?string $vaultUser = null,
        ?string $vaultPassword = null
    ): array {
        $results = [];
        $isCdb = ($targetContainer === 'CDB' || $targetContainer === 'CDB$ROOT');

        if ($isCdb) {
            $currentCon = $this->db->getCurrentContainer();
            if ($currentCon !== 'CDB$ROOT') {
                $this->db->switchContainer('CDB$ROOT');
            }

            $step = 1;
            // 1. Droits et quotas
            if ($reapplyGrants) {
                $file = $this->installerPath . '/common/001_create_common_components.sql';
                try {
                    $this->executeSqlScript($file);
                    $results[] = ['step' => $step++, 'name' => 'Réapplication des droits et quotas CDB (001)', 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['step' => $step++, 'name' => 'Droits et quotas CDB (001)', 'status' => 'FAILED', 'error' => $e->getMessage()];
                }
            }

            // 2. CREATE OR REPLACE Packages
            if ($replaceObjects) {
                $file = $this->installerPath . '/common/003_create_common_packages.sql';
                try {
                    $this->executeSqlScript($file);
                    $results[] = ['step' => $step++, 'name' => 'Remplacement Package PKG_ZIRIOAM_COMMON_OPS (CREATE OR REPLACE)', 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                    if (strpos($err, 'ORA-24344') !== false) {
                        $details = $this->fetchCompilationErrors();
                        if (!empty($details)) $err .= " [" . implode(' | ', $details) . "]";
                    }
                    $results[] = ['step' => $step++, 'name' => 'Package PKG_ZIRIOAM_COMMON_OPS', 'status' => 'FAILED', 'error' => $err];
                }
            }

            // 3. Réapplication des GRANTs d'exécution
            if ($reapplyGrants) {
                $file = $this->installerPath . '/common/004_common_privileges.sql';
                try {
                    $this->executeSqlScript($file);
                    $results[] = ['step' => $step++, 'name' => 'Réapplication des GRANTs d\'exécution (004)', 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['step' => $step++, 'name' => 'GRANTs d\'exécution (004)', 'status' => 'FAILED', 'error' => $e->getMessage()];
                }
            }

            // 4. Habilitation Vault Manager si fournie
            if (!empty($vaultUser) && !empty($vaultPassword)) {
                try {
                    $this->grantVaultManagerPrivileges($vaultUser, $vaultPassword);
                    $results[] = ['step' => $step++, 'name' => "Habilitation Database Vault via {$vaultUser} (ALTER USER, DV_ACCTMGR)", 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $ve) {
                    $results[] = ['step' => $step++, 'name' => "Habilitation Database Vault via {$vaultUser}", 'status' => 'FAILED', 'error' => $ve->getMessage()];
                }
            }
        } else {
            // Cible PDB
            $this->db->switchContainer($targetContainer);
            $step = 1;

            if ($reapplyGrants) {
                try {
                    $this->executeSqlScript($this->installerPath . '/pdb/001_create_zirioam_owner.sql');
                    $this->executeSqlScript($this->installerPath . '/pdb/002_create_tables.sql');
                    $this->executeSqlScript($this->installerPath . '/pdb/003_create_sequences.sql');
                    $this->executeSqlScript($this->installerPath . '/pdb/004_create_roles.sql');
                    $results[] = ['step' => $step++, 'name' => "Création schéma, tables et rôles locaux PDB ({$targetContainer})", 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['step' => $step++, 'name' => "Schéma et tables locaux PDB", 'status' => 'FAILED', 'error' => $e->getMessage()];
                }
            }

            if ($replaceObjects) {
                try {
                    $this->executeSqlScript($this->installerPath . '/pdb/005_create_packages.sql');
                    $results[] = ['step' => $step++, 'name' => "Remplacement Packages PDB (PKG_ZIRIOAM_PDB_ACCESS & DDL_ENGINE)", 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                    if (strpos($err, 'ORA-24344') !== false) {
                        $details = $this->fetchCompilationErrors();
                        if (!empty($details)) $err .= " [" . implode(' | ', $details) . "]";
                    }
                    $results[] = ['step' => $step++, 'name' => "Packages PDB", 'status' => 'FAILED', 'error' => $err];
                }

                try {
                    $this->executeSqlScript($this->installerPath . '/pdb/006_create_views.sql');
                    $results[] = ['step' => $step++, 'name' => "Remplacement Vues PDB (CREATE OR REPLACE VIEW)", 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['step' => $step++, 'name' => "Vues PDB", 'status' => 'FAILED', 'error' => $e->getMessage()];
                }
            }

            if ($reapplyGrants) {
                try {
                    $this->executeSqlScript($this->installerPath . '/pdb/007_initial_data.sql');
                    $results[] = ['step' => $step++, 'name' => "Enregistrement état et paramètres PDB (007)", 'status' => 'SUCCESS', 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['step' => $step++, 'name' => "Enregistrement PDB", 'status' => 'FAILED', 'error' => $e->getMessage()];
                }
            }
        }

        return $results;
    }

    /**
     * Exécute l'installation des composants locaux dans une PDB donnée
     */
    public function installPdbComponents(string $pdbName, callable $stepCallback = null): array
    {
        $scripts = $this->manifest['pdb_scripts'] ?? [];
        $results = [];

        // Bascule dans la PDB cible
        $this->db->switchContainer($pdbName);

        $totalSteps = count($scripts);

        foreach ($scripts as $idx => $scriptMeta) {
            $stepNum = $idx + 1;
            $progressPercent = (int)round(($stepNum / $totalSteps) * 100);

            $scriptFile = $this->installerPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $scriptMeta['file']);
            if (!file_exists($scriptFile)) {
                $err = "Fichier script local PDB manquant : {$scriptFile}";
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'FAILED', 'error' => $err];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'FAILED', $err);
                break;
            }

            try {
                $this->executeSqlScript($scriptFile);
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'SUCCESS', 'error' => null];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'SUCCESS', null);
            } catch (\Throwable $e) {
                $err = "Erreur Oracle lors de l'étape PDB {$stepNum} : " . $e->getMessage();
                if (strpos($e->getMessage(), 'ORA-24344') !== false) {
                    $details = $this->fetchCompilationErrors();
                    if (!empty($details)) {
                        $err .= " [Détails: " . implode(' | ', $details) . "]";
                    }
                }
                $results[] = ['step' => $stepNum, 'name' => $scriptMeta['name'], 'status' => 'FAILED', 'error' => $err];
                if ($stepCallback) $stepCallback($stepNum, $totalSteps, $progressPercent, $scriptMeta['name'], 'FAILED', $err);
                break;
            }
        }

        return $results;
    }

    /**
     * Récupère les erreurs de compilation PL/SQL depuis ALL_ERRORS
     * @return array<string>
     */
    private function fetchCompilationErrors(): array
    {
        $errors = [];
        try {
            $sql = "SELECT OWNER, NAME, TYPE, LINE, POSITION, TEXT 
                    FROM ALL_ERRORS 
                    WHERE OWNER IN ('C##ZIRIOAM_ADMIN', 'ZIRIOAM_OWNER') 
                    ORDER BY OWNER, NAME, TYPE, SEQUENCE";
            $rows = $this->db->query($sql);
            foreach ($rows as $r) {
                $owner = $r['OWNER'] ?? '';
                $name  = $r['NAME'] ?? '';
                $type  = $r['TYPE'] ?? '';
                $line  = $r['LINE'] ?? '';
                $pos   = $r['POSITION'] ?? '';
                $text  = trim($r['TEXT'] ?? '');
                $errors[] = "{$owner}.{$name} ({$type} L:{$line} P:{$pos}) : {$text}";
            }
        } catch (\Throwable $e) {
            // Tolérance si ALL_ERRORS n'est pas lisible
        }
        return $errors;
    }

    /**
     * Découpe et exécute les blocs PL/SQL et DDL d'un fichier .sql
     */
    private function executeSqlScript(string $filePath): void
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Impossible de lire le script SQL : {$filePath}");
        }

        // Nettoyer les sauts de ligne
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Découpage par '/' sur une ligne seule (séparateur standard de blocs PL/SQL Oracle)
        $blocks = preg_split('/^[ \t]*\/[ \t]*$/m', $raw);

        foreach ($blocks as $block) {
            $trimmed = trim($block);
            if (empty($trimmed)) {
                continue;
            }

            // Supprimer les commentaires d'en-tête de ligne pure --
            $lines = explode("\n", $trimmed);
            $cleanLines = [];
            foreach ($lines as $line) {
                $lineTrim = trim($line);
                if (str_starts_with($lineTrim, '--')) {
                    continue;
                }
                $cleanLines[] = $line;
            }

            $sqlToRun = trim(implode("\n", $cleanLines));
            if (empty($sqlToRun)) {
                continue;
            }

            // Retirer le point-virgule final si c'est une simple instruction SQL DDL
            if (!preg_match('/^(BEGIN|DECLARE|CREATE\s+OR\s+REPLACE\s+(PACKAGE|PROCEDURE|FUNCTION|TRIGGER))/i', $sqlToRun)) {
                $sqlToRun = rtrim($sqlToRun, ';');
            }

            // Exécution
            $this->db->executeDdl($sqlToRun);
        }
    }
}

