<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service de Diagnostic et de Réparation / Reprise d'Installation ZIRIOAM
 */
class InstallationRecoveryService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Tente de recompiler les packages invalides
     */
    public function recompileInvalidObjects(): array
    {
        $currentCon = $this->db->getCurrentContainer();
        $owner = ($currentCon === 'CDB$ROOT') ? 'C##ZIRIOAM_ADMIN' : 'ZIRIOAM_OWNER';

        $sql = "SELECT OBJECT_NAME, OBJECT_TYPE FROM ALL_OBJECTS 
                WHERE OWNER = :owner AND STATUS = 'INVALID' 
                ORDER BY OBJECT_TYPE, OBJECT_NAME";
        $invalidObjects = $this->db->query($sql, ['owner' => $owner]);

        $results = [];
        foreach ($invalidObjects as $obj) {
            $name = $obj['OBJECT_NAME'];
            $type = $obj['OBJECT_TYPE'];
            $compileCmd = "ALTER {$type} {$owner}.{$name} COMPILE";
            if ($type === 'PACKAGE') {
                $compileCmd = "ALTER PACKAGE {$owner}.{$name} COMPILE";
            } elseif ($type === 'PACKAGE BODY') {
                $compileCmd = "ALTER PACKAGE {$owner}.{$name} COMPILE BODY";
            } elseif ($type === 'VIEW') {
                $compileCmd = "ALTER VIEW {$owner}.{$name} COMPILE";
            }

            try {
                $this->db->executeDdl($compileCmd);
                $results[] = ['object' => "{$owner}.{$name}", 'type' => $type, 'status' => 'RECOMPILED', 'error' => null];
            } catch (\Throwable $e) {
                $results[] = ['object' => "{$owner}.{$name}", 'type' => $type, 'status' => 'FAILED', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Nettoie et réinitialise les logs d'étape en échec
     */
    public function resetFailedStepLogs(): void
    {
        $currentCon = $this->db->getCurrentContainer();
        $owner = ($currentCon === 'CDB$ROOT') ? 'C##ZIRIOAM_ADMIN' : 'ZIRIOAM_OWNER';

        try {
            $this->db->execute("DELETE FROM {$owner}.ZIRIOAM_INSTALL_LOG WHERE STATUS = 'FAILED'");
        } catch (\Throwable $e) {
            // Table non créée encore
        }
    }
}

