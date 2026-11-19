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

        $forceView = $request->query('view') === '1';
        // Si ZIRIOAM est déjà installé et validé, ouvrir le tableau de bord sauf demande explicite
        if ($cdbStatus['status'] === InstallationDetectorService::INSTALLED && !$forceView) {
            return $this->redirect('/dashboard');
        }

        $recoveryService = new InstallationRecoveryService($db);
        $invalidObjects = $recoveryService->getInvalidObjects();

        // Afficher l'écran d'état ou d'initialisation obligatoire (Section 9)
        return $this->render('installer.status', [
            'cdbStatus' => $cdbStatus,
            'invalidObjects' => $invalidObjects,
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

        // Récupération des identifiants Vault Manager (depuis le wizard ou la session)
        $vaultUser = trim((string)$request->post('vault_user_forward', ''));
        $vaultPassword = (string)$request->post('vault_password_forward', '');

        if (empty($vaultUser)) {
            $vaultUser = (string)$this->session->get('vault_user', '');
            $vaultPassword = (string)$this->session->get('vault_password', '');
        }

        if ($target === 'CDB') {
            $stepResults = $installerService->installCdbComponents($vaultUser, $vaultPassword);
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
     * Interface de Maintenance et Réexécution des Composants
     */
    public function maintenance(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $discoveryService = new \App\Services\OracleDiscoveryService($db);
        $pdbs = $discoveryService->discoverPdbs();

        $sessVaultUser = $this->session->get('vault_user', '');

        return $this->render('installer.maintenance', [
            'pdbs' => $pdbs,
            'sessVaultUser' => $sessVaultUser,
            'server' => $this->session->get('current_server'),
            'results' => null,
            'target' => 'CDB$ROOT',
        ]);
    }

    /**
     * Traitement de la Réexécution / Mise à jour des Composants
     */
    public function redeploy(Request $request): Response
    {
        $db = $this->connectionService->getActiveConnection();
        if (!$db) return $this->redirect('/servers');

        $target = (string)$request->post('target_container', 'CDB$ROOT');
        $replaceObjects = $request->post('replace_objects') === '1';
        $reapplyGrants = $request->post('reapply_grants') === '1';
        $reapplyVault = $request->post('reapply_vault') === '1';

        $vaultUser = null;
        $vaultPassword = null;
        if ($reapplyVault) {
            $vaultUser = trim((string)$request->post('vault_user', ''));
            $vaultPassword = (string)$request->post('vault_password', '');

            if (empty($vaultUser)) {
                $vaultUser = (string)$this->session->get('vault_user', '');
                $vaultPassword = (string)$this->session->get('vault_password', '');
            }
        }

        $installerService = new OracleInstallerService($db);
        $results = $installerService->redeployComponents(
            $target,
            $replaceObjects,
            $reapplyGrants,
            !empty($vaultUser) ? $vaultUser : null,
            !empty($vaultPassword) ? $vaultPassword : null
        );

        $hasFailed = false;
        foreach ($results as $r) {
            if ($r['status'] === 'FAILED') {
                $hasFailed = true;
                break;
            }
        }

        if ($hasFailed) {
            $this->flash('warning', "La réexécution s'est terminée avec des avertissements ou erreurs.");
        } else {
            $this->flash('success', "Tous les composants ZIRIOAM sur {$target} ont été mis à jour avec succès !");
        }

        $discoveryService = new \App\Services\OracleDiscoveryService($db);
        $pdbs = $discoveryService->discoverPdbs();

        return $this->render('installer.maintenance', [
            'pdbs' => $pdbs,
            'sessVaultUser' => $vaultUser,
            'server' => $this->session->get('current_server'),
            'results' => $results,
            'target' => $target,
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

