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
    public function installCdbComponents(callable $stepCallback = null): array
    {
        $scripts = $this->manifest['common_scripts'] ?? [];
        $results = [];

        // S'assurer d'être sur CDB$ROOT
        $currentCon = $this->db->getCurrentContainer();
        if ($currentCon !== 'CDB$ROOT') {
            $this->db->switchContainer('CDB$ROOT');
        }

        $totalSteps = count($scripts);

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

