<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service de Détection Automatique de l'Installation ZIRIOAM
 */
class InstallationDetectorService
{
    public const NOT_INSTALLED = 'NOT_INSTALLED';
    public const INSTALLED = 'INSTALLED';
    public const OUTDATED = 'OUTDATED';
    public const INCOMPLETE = 'INCOMPLETE';
    public const INVALID = 'INVALID';
    public const INSTALLATION_FAILED = 'INSTALLATION_FAILED';

    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Analyse l'état d'installation complet dans le conteneur courant ou pour la CDB
     */
    public function detectStatus(string $targetContainer = 'CDB$ROOT'): array
    {
        $currentCon = $this->db->getCurrentContainer();
        $isCdb = ($targetContainer === 'CDB$ROOT' || $currentCon === 'CDB$ROOT');
        $owner = $isCdb ? 'C##ZIRIOAM_ADMIN' : 'ZIRIOAM_OWNER';

        // 1. Vérification de la présence de la table de contrôle ZIRIOAM_INSTALLATION
        $tableCheckSql = "SELECT COUNT(*) AS CNT FROM ALL_TABLES WHERE OWNER = :owner AND TABLE_NAME = 'ZIRIOAM_INSTALLATION'";
        $row = $this->db->queryOne($tableCheckSql, ['owner' => $owner]);
        $tableExists = ((int)($row['CNT'] ?? 0)) > 0;

        if (!$tableExists) {
            return [
                'status' => self::NOT_INSTALLED,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => null,
                'message' => "Le système ZIRIOAM n'est pas encore installé sur ce conteneur ({$currentCon}).",
                'details' => [],
            ];
        }

        // 2. Lecture de l'enregistrement dans ZIRIOAM_INSTALLATION
        $installRow = null;
        try {
            $installRow = $this->db->queryOne("SELECT PRODUCT_CODE, PRODUCT_VERSION, INSTALL_STATUS, INSTALL_DATE FROM {$owner}.ZIRIOAM_INSTALLATION WHERE PRODUCT_CODE = 'ZIRIOAM_DEVELOPER_MANAGER'");
        } catch (\Throwable $e) {
            // Table existe mais corrompue
            return [
                'status' => self::INCOMPLETE,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => null,
                'message' => "La table de contrôle ZIRIOAM_INSTALLATION est corrompue ou inaccessible : " . $e->getMessage(),
                'details' => [],
            ];
        }

        if (!$installRow) {
            return [
                'status' => self::INCOMPLETE,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => null,
                'message' => "Enregistrement de version absent dans {$owner}.ZIRIOAM_INSTALLATION.",
                'details' => [],
            ];
        }

        $recordedVersion = $installRow['PRODUCT_VERSION'] ?? '0.0.0';
        $manifestVersion = '1.0.0';

        // 3. Vérification des tables obligatoires
        $requiredTables = $isCdb
            ? ['ZIRIOAM_INSTALLATION', 'ZIRIOAM_INSTALL_LOG', 'ZIRIOAM_DEVELOPERS', 'ZIRIOAM_PWD_RESET_LOG', 'ZIRIOAM_CDB_AUDIT', 'ZIRIOAM_SETTINGS']
            : ['ZIRIOAM_INSTALLATION', 'ZIRIOAM_INSTALL_LOG', 'ZIRIOAM_ACCESS_ROLES', 'ZIRIOAM_ROLE_PRIVILEGES', 'ZIRIOAM_DEVELOPER_ACCESS', 'ZIRIOAM_EXECUTION_HISTORY', 'ZIRIOAM_AUDIT_LOG', 'ZIRIOAM_SETTINGS'];

        $missingTables = [];
        foreach ($requiredTables as $tbl) {
            $checkSql = "SELECT COUNT(*) AS CNT FROM ALL_TABLES WHERE OWNER = :owner AND TABLE_NAME = :tbl";
            $res = $this->db->queryOne($checkSql, ['owner' => $owner, 'tbl' => $tbl]);
            if (((int)($res['CNT'] ?? 0)) === 0) {
                $missingTables[] = $tbl;
            }
        }

        if (!empty($missingTables)) {
            return [
                'status' => self::INCOMPLETE,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => $recordedVersion,
                'message' => "Composants incomplets. Tables manquantes : " . implode(', ', $missingTables),
                'details' => ['missing_tables' => $missingTables],
            ];
        }

        // 4. Vérification des packages et de leur statut VALID
        $requiredPackages = $isCdb
            ? ['PKG_ZIRIOAM_COMMON_OPS']
            : ['PKG_ZIRIOAM_PDB_ACCESS', 'PKG_ZIRIOAM_DDL_ENGINE'];

        $invalidPackages = [];
        $missingPackages = [];
        foreach ($requiredPackages as $pkg) {
            $pkgSql = "SELECT OBJECT_NAME, STATUS FROM ALL_OBJECTS WHERE OWNER = :owner AND OBJECT_NAME = :pkg AND OBJECT_TYPE IN ('PACKAGE', 'PACKAGE BODY')";
            $objects = $this->db->query($pkgSql, ['owner' => $owner, 'pkg' => $pkg]);

            if (empty($objects)) {
                $missingPackages[] = $pkg;
            } else {
                foreach ($objects as $obj) {
                    if (($obj['STATUS'] ?? '') !== 'VALID') {
                        $invalidPackages[] = "{$pkg} (" . ($obj['OBJECT_TYPE'] ?? '') . ")";
                    }
                }
            }
        }

        if (!empty($missingPackages)) {
            return [
                'status' => self::INCOMPLETE,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => $recordedVersion,
                'message' => "Packages PL/SQL manquants : " . implode(', ', $missingPackages),
                'details' => ['missing_packages' => $missingPackages],
            ];
        }

        if (!empty($invalidPackages)) {
            return [
                'status' => self::INVALID,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => $recordedVersion,
                'message' => "Packages PL/SQL invalides détectés : " . implode(', ', $invalidPackages),
                'details' => ['invalid_packages' => $invalidPackages],
            ];
        }

        // 5. Vérification des versions
        if (version_compare($recordedVersion, $manifestVersion, '<')) {
            return [
                'status' => self::OUTDATED,
                'container' => $currentCon,
                'owner' => $owner,
                'version' => $recordedVersion,
                'manifest_version' => $manifestVersion,
                'message' => "Composants obsolètes ({$recordedVersion} < {$manifestVersion}). Mise à jour requise.",
                'details' => [],
            ];
        }

        // 6. Succès complet : INSTALLED
        return [
            'status' => self::INSTALLED,
            'container' => $currentCon,
            'owner' => $owner,
            'version' => $recordedVersion,
            'install_date' => $installRow['INSTALL_DATE'] ?? null,
            'message' => "Le système ZIRIOAM est opérationnel et validé sur {$currentCon}.",
            'details' => [
                'tables_count' => count($requiredTables),
                'packages_count' => count($requiredPackages),
            ],
        ];
    }
}

