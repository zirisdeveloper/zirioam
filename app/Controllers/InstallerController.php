<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OracleConnectionService;
use App\Services\InstallationDetectorService;
use App\Services\OracleInstallerService;
use App\Services\InstallationRecoveryService;

/**
 * Contrôleur de Détection et d'Installation Automatique ZIRIOAM
 */
class InstallerController extends Controller
{
    private OracleConnectionService $connectionService;

    public function __construct()
    {
        parent::__construct();
        $this->connectionService = new OracleConnectionService();
    }

    /**
     * Écran d'état d'installation du serveur (Section 8 et Section 9)
     */
    public function status(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) {
            return $this->redirect('/servers');
        }

        $detector = new InstallationDetectorService($db);
        $cdbStatus = $detector->detectStatus('CDB$ROOT');

        // Si ZIRIOAM est déjà installé et validé, ouvrir le tableau de bord
        if ($cdbStatus['status'] === InstallationDetectorService::INSTALLED) {
            return $this->redirect('/dashboard');
        }

        // Sinon, afficher l'écran d'initialisation obligatoire (Section 9)
        return $this->render('installer.status', [
            'cdbStatus' => $cdbStatus,
            'server' => $this->session->get('current_server'),
            'currentCdb' => $this->session->get('current_cdb', 'CDB_PROD'),
        ]);
    }

    /**
     * Assistant d'installation pré-requis et confirmation (Section 9.1)
     */
    public function wizard(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) {
            return $this->redirect('/servers');
        }

        $installerService = new OracleInstallerService($db);
        $manifest = $installerService->getManifest();

        // Vérification des prérequis
        $versionRow = $db->queryOne("SELECT BANNER_FULL FROM V\$VERSION WHERE ROWNUM = 1");
        $banner = $versionRow['BANNER_FULL'] ?? 'Oracle Database 19c';

        $conRow = $db->queryOne("SELECT SYS_CONTEXT('USERENV', 'CON_NAME') AS CON_NAME FROM DUAL");
        $currentContainer = $conRow['CON_NAME'] ?? 'CDB$ROOT';

        $dbaRoleRow = $db->queryOne("SELECT COUNT(*) AS CNT FROM SESSION_ROLES WHERE ROLE IN ('DBA', 'SYSDBA')");
        $hasDbaPrivs = ((int)($dbaRoleRow['CNT'] ?? 0)) > 0 || ($this->session->get('current_mode') === 'SYSDBA');

        return $this->render('installer.wizard', [
            'banner' => $banner,
            'currentContainer' => $currentContainer,
            'hasDbaPrivs' => $hasDbaPrivs,
            'manifest' => $manifest,
        ]);
    }

    /**
     * Exécution effective de l'installation pas-à-pas (Section 9.2)
     */
    public function execute(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) {
            return $this->redirect('/servers');
        }

        $installerService = new OracleInstallerService($db);
        $target = $request->post('target', 'CDB'); // 'CDB' ou nom de PDB
        $stepResults = [];

        if ($target === 'CDB') {
            $stepResults = $installerService->installCdbComponents();
        } else {
            $stepResults = $installerService->installPdbComponents($target);
        }

        // Vérifier s'il y a un échec
        $hasFailed = false;
        foreach ($stepResults as $step) {
            if ($step['status'] === 'FAILED') {
                $hasFailed = true;
                break;
            }
        }

        if ($hasFailed) {
            $this->flash('danger', "L'installation a rencontré une erreur Oracle lors de l'exécution d'une étape.");
        } else {
            $this->flash('success', "Initialisation ZIRIOAM terminée avec succès sur {$target} !");
            return $this->redirect('/dashboard');
        }

        return $this->render('installer.progress', [
            'stepResults' => $stepResults,
            'target' => $target,
            'hasFailed' => $hasFailed,
        ]);
    }

    /**
     * Reprise et réparation après échec (Section 9.3)
     */
    public function repair(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) {
            return $this->redirect('/servers');
        }

        $recoveryService = new InstallationRecoveryService($db);
        $recompileResults = $recoveryService->recompileInvalidObjects();

        $this->flash('info', "Tentative de réparation et de recompilation exécutée.");
        return $this->redirect('/installer/status');
    }
}

