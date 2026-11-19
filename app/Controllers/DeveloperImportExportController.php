<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\DeveloperExportService;
use App\Services\DeveloperImportService;

/**
 * Contrôleur d'Importation / Exportation JSON des Développeurs (Section 14)
 */
class DeveloperImportExportController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Exporte les identités des développeurs au format JSON ZIRIOAM_DEVELOPERS
     */
    public function export(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $exportService = new DeveloperExportService($db);
        $server = $this->session->get('current_server');
        $serverName = $server['server_name'] ?? 'ORACLE_SERVER';

        $data = $exportService->generateExport($serverName);
        $fileName = 'ZIRIOAM_DEVELOPERS_' . date('Y-m-d') . '.json';

        $response = new Response();
        $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response;
    }

    /**
     * Affiche l'écran de sélection de fichier JSON à importer
     */
    public function showImport(Request $request): Response
    {
        return $this->render('developers.import', [
            'analysis' => null,
        ]);
    }

    /**
     * Traite l'analyse préalable du fichier (Aperçu ADD ONLY)
     */
    public function previewImport(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $file = $request->file('import_file');
        if (!$file || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            $this->flash('danger', "Veuillez sélectionner un fichier JSON valide à importer.");
            return $this->redirect('/developers/import');
        }

        // Limite de taille de sécurité (max 5 Mo)
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->flash('danger', "Le fichier dépasse la taille maximale autorisée (5 Mo).");
            return $this->redirect('/developers/import');
        }

        $rawJson = file_get_contents($file['tmp_name']);
        $importService = new DeveloperImportService($db);

        try {
            $analysis = $importService->analyzeImport($rawJson);
            
            // Stocker temporairement la liste des nouveaux développeurs à importer
            $this->session->set('_pending_import_new', $analysis['new_developers']);

            return $this->render('developers.import', [
                'analysis' => $analysis,
                'fileName' => $file['name'],
            ]);
        } catch (\Throwable $e) {
            $this->flash('danger', "Erreur lors de l'analyse du fichier : " . $e->getMessage());
            return $this->redirect('/developers/import');
        }
    }

    /**
     * Confirmation de l'importation exclusive des nouveaux développeurs (ADD ONLY)
     */
    public function confirmImport(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $newDevelopers = $this->session->get('_pending_import_new', []);
        $this->session->remove('_pending_import_new');

        if (empty($newDevelopers)) {
            $this->flash('warning', "Aucun nouveau développeur à importer (liste vide ou déjà importée).");
            return $this->redirect('/developers');
        }

        $importService = new DeveloperImportService($db);
        $dbaUser = $this->session->get('current_user', 'DBA');
        $clientIp = $request->header('X-Forwarded-For') ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $report = $importService->executeAddOnlyImport($newDevelopers, $dbaUser, $clientIp);

        $this->flash(
            'success', 
            "Importation ADD ONLY terminée avec succès : {$report['imported_count']} nouveau(x) développeur(s) ajouté(s). " .
            "Les développeurs déjà existants sont restés strictement inchangés. Aucun compte Oracle ni privilège n'a été créé automatiquement."
        );

        return $this->redirect('/developers');
    }
}

