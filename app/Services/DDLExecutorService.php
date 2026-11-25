<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service d'Exécution Contrôlée des Ordres DDL (Moteur DDL Sécurisé)
 */
class DDLExecutorService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Exécute un ordre DDL contrôlé pour un développeur donné
     */
    public function executeDdl(
        string $pdbName,
        string $developerUsername,
        string $targetSchema,
        string $operation,
        string $objectType,
        string $objectName,
        string $ddlStatement
    ): array {
        $this->db->switchContainer($pdbName);

        $normUser = strtoupper(trim($developerUsername));
        $oracleUser = str_starts_with($normUser, 'C##') ? $normUser : 'C##' . $normUser;
        $schema = strtoupper(trim($targetSchema));
        $op = strtoupper(trim($operation));
        $objType = strtoupper(trim($objectType));
        $objName = strtoupper(trim($objectName));

        $allowedObjectTypes = [
            'TABLE', 'VIEW', 'INDEX', 'SEQUENCE', 'PROCEDURE', 'FUNCTION', 'PACKAGE',
            'CONSTRAINT', 'PACKAGE BODY', 'TRIGGER', 'TYPE', 'TYPE BODY', 'SYNONYM',
        ];
        if (!in_array($objType, $allowedObjectTypes, true)) {
            $msg = "Type d'objet DDL non autorisé : {$objType}.";
            $this->logExecution($oracleUser, $schema, $op, $objType, $objName, $ddlStatement, 'REJECTED', 0, $msg);
            return ['status' => 'REJECTED', 'error' => $msg];
        }

        // 1. Validation de la matrice d'accès
        $colCheck = null;
        if (in_array($op, ['CREATE', 'ADD'], true)) {
            $colCheck = 'CAN_DDL_CREATE';
        } elseif (in_array($op, ['ALTER', 'REPLACE'], true)) {
            $colCheck = 'CAN_DDL_ALTER';
        } elseif ($op === 'DROP') {
            $colCheck = 'CAN_DDL_DROP';
        }

        if (!$colCheck) {
            return ['status' => 'REJECTED', 'error' => "Opération DDL non supportée : {$op}."];
        }

        $checkSql = "SELECT COUNT(*) AS CNT FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS 
                     WHERE USERNAME = :u AND TARGET_SCHEMA = :s AND {$colCheck} = 1 AND STATUS = 'ACTIVE'";
        $row = $this->db->queryOne($checkSql, ['u' => $oracleUser, 's' => $schema]);

        if (((int)($row['CNT'] ?? 0)) === 0) {
            $msg = "Accès refusé : Le développeur {$oracleUser} ne possède pas le privilège DDL {$op} sur le schéma {$schema}.";
            $this->logExecution($oracleUser, $schema, $op, $objType, $objName, $ddlStatement, 'REJECTED', 0, $msg);
            return ['status' => 'REJECTED', 'error' => $msg];
        }

        // 2. Filtrage des clauses interdites
        $cleanStmt = strtoupper($ddlStatement);
        $forbiddenKeywords = ['ORGANIZATION EXTERNAL', 'ALTER SYSTEM', 'ALTER DATABASE', 'DBMS_SYS_SQL', 'EXECUTE IMMEDIATE'];
        foreach ($forbiddenKeywords as $bad) {
            if (str_contains($cleanStmt, $bad)) {
                $msg = "Clause de sécurité interdite détectée : '{$bad}'.";
                $this->logExecution($oracleUser, $schema, $op, $objType, $objName, $ddlStatement, 'REJECTED', 0, $msg);
                return ['status' => 'REJECTED', 'error' => $msg];
            }
        }

        // 3. Exécution effective sous ZIRIOAM_OWNER
        try {
            $this->db->executeDdl($ddlStatement);
            $this->logExecution($oracleUser, $schema, $op, $objType, $objName, $ddlStatement, 'SUCCESS', null, null);
            return ['status' => 'SUCCESS', 'error' => null];
        } catch (\Throwable $e) {
            $errCode = method_exists($e, 'getOracleCode') ? $e->getOracleCode() : 0;
            $errMsg = $e->getMessage();
            $this->logExecution($oracleUser, $schema, $op, $objType, $objName, $ddlStatement, 'FAILED', $errCode, $errMsg);
            return ['status' => 'FAILED', 'error' => $errMsg];
        }
    }

    private function logExecution(
        string $user,
        string $schema,
        string $op,
        string $type,
        string $name,
        string $stmt,
        string $status,
        ?int $errCode,
        ?string $errMsg
    ): void {
        try {
            $sql = "INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY 
                    (USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME, DDL_STATEMENT, EXECUTION_STATUS, ORACLE_ERROR_CODE, ORACLE_ERROR_MESSAGE, EXECUTED_AT)
                    VALUES (:u, :s, :op, :t, :n, :stmt, :stat, :ec, :em, SYSTIMESTAMP)";
            $this->db->execute($sql, [
                'u' => $user,
                's' => $schema,
                'op' => $op,
                't' => $type,
                'n' => $name,
                'stmt' => substr($stmt, 0, 4000),
                'stat' => $status,
                'ec' => $errCode,
                'em' => $errMsg ? substr($errMsg, 0, 3900) : null,
            ]);
        } catch (\Throwable $e) {
            // Ignorer
        }
    }

    /**
     * Récupère l'historique récent des ordres DDL exécutés
     */
    public function getExecutionHistory(string $pdbName, int $limit = 50): array
    {
        $this->db->switchContainer($pdbName);

        $sql = "SELECT EXECUTION_ID, USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME,
                       EXECUTION_STATUS, ORACLE_ERROR_CODE, ORACLE_ERROR_MESSAGE,
                       TO_CHAR(EXECUTED_AT, 'YYYY-MM-DD HH24:MI:SS') AS EXECUTED_AT
                FROM ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY
                ORDER BY EXECUTED_AT DESC";

        try {
            return $this->db->query($sql);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

