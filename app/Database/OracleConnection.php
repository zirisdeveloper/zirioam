<?php
namespace App\Database;

/**
 * Abstraction de Connexion Oracle Database 19c via OCI8
 */
class OracleConnection
{
    private $connection = null;
    private string $host;
    private int $port;
    private string $service;
    private string $username;
    private string $mode;
    private string $charset;
    private bool $inTransaction = false;

    public function __construct(
        string $host,
        int $port,
        string $service,
        string $username,
        string $password,
        string $mode = 'NORMAL',
        string $charset = 'AL32UTF8'
    ) {
        if (!extension_loaded('oci8')) {
            throw new \RuntimeException(
                "L'extension PHP OCI8 n'est pas chargée. Veuillez installer et activer le module oci8 pour PHP 8.2+ ainsi que l'Oracle Instant Client."
            );
        }

        $this->host = $host;
        $this->port = $port;
        $this->service = $service;
        $this->username = $username;
        $this->mode = strtoupper($mode);
        $this->charset = $charset;

        $this->connect($password);
    }

    private function connect(string $password): void
    {
        // Détermination du mode OCI8
        $sessionMode = OCI_DEFAULT;
        if ($this->mode === 'SYSDBA') {
            $sessionMode = OCI_SYSDBA;
        }

        // Chaîne Easy Connect Oracle 19c : //host:port/service
        $easyConnect = "//{$this->host}:{$this->port}/{$this->service}";

        // Connexion
        $conn = @oci_connect(
            $this->username,
            $password,
            $easyConnect,
            $this->charset,
            $sessionMode
        );

        if (!$conn) {
            $err = oci_error();
            $message = $err['message'] ?? 'Erreur inconnue de connexion Oracle.';
            $code = $err['code'] ?? 0;
            throw new OracleQueryException("Échec de connexion Oracle : {$message}", $code);
        }

        $this->connection = $conn;

        // Configuration du format de date par défaut de la session
        $this->execute("ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'");
        $this->execute("ALTER SESSION SET NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF'");
    }

    /**
     * Exécute une requête SELECT avec des variables de liaison (Bind Variables)
     */
    public function query(string $sql, array $binds = []): array
    {
        $stmt = $this->prepareAndExecute($sql, $binds);
        $results = [];

        while (($row = oci_fetch_assoc($stmt)) !== false) {
            $results[] = $row;
        }

        oci_free_statement($stmt);
        return $results;
    }

    /**
     * Exécute une requête SELECT retournant une seule ligne
     */
    public function queryOne(string $sql, array $binds = []): ?array
    {
        $stmt = $this->prepareAndExecute($sql, $binds);
        $row = oci_fetch_assoc($stmt);
        oci_free_statement($stmt);

        return $row !== false ? $row : null;
    }

    /**
     * Exécute une requête DML (INSERT, UPDATE, DELETE) avec commit si non en transaction manuelle
     */
    public function execute(string $sql, array $binds = []): bool
    {
        $mode = $this->inTransaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
        $stmt = $this->prepareAndExecute($sql, $binds, $mode);
        oci_free_statement($stmt);
        return true;
    }

    /**
     * Exécute un ordre DDL (CREATE, ALTER, DROP)
     * Remarque : Les DDL en Oracle déclenchent un commit implicite immédiat
     */
    public function executeDdl(string $sql): bool
    {
        $stmt = @oci_parse($this->connection, $sql);
        if (!$stmt) {
            $err = oci_error($this->connection);
            throw new OracleQueryException($err['message'] ?? 'Erreur lors du parse DDL', $err['code'] ?? 0, $err['offset'] ?? null, $sql);
        }

        $res = @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        if (!$res) {
            $err = oci_error($stmt);
            oci_free_statement($stmt);
            throw new OracleQueryException($err['message'] ?? 'Erreur lors de l\'exécution DDL', $err['code'] ?? 0, $err['offset'] ?? null, $sql);
        }

        oci_free_statement($stmt);
        return true;
    }

    /**
     * Change le conteneur courant dans la session multitenant
     */
    public function switchContainer(string $containerName): bool
    {
        $containerName = trim($containerName);
        if (!zirioam_is_valid_oracle_identifier($containerName)) {
            throw new \InvalidArgumentException("Nom de conteneur invalide : {$containerName}");
        }

        return $this->executeDdl("ALTER SESSION SET CONTAINER = " . $containerName);
    }

    /**
     * Retourne le nom du conteneur actif
     */
    public function getCurrentContainer(): string
    {
        $row = $this->queryOne("SELECT SYS_CONTEXT('USERENV', 'CON_NAME') AS CON_NAME FROM DUAL");
        return $row['CON_NAME'] ?? 'UNKNOWN';
    }

    /**
     * Démarre une transaction
     */
    public function beginTransaction(): void
    {
        $this->inTransaction = true;
    }

    /**
     * Valide la transaction courante
     */
    public function commit(): void
    {
        if ($this->connection && $this->inTransaction) {
            if (!oci_commit($this->connection)) {
                $err = oci_error($this->connection);
                throw new OracleQueryException($err['message'] ?? 'Erreur lors du COMMIT', $err['code'] ?? 0);
            }
            $this->inTransaction = false;
        }
    }

    /**
     * Annule la transaction courante
     */
    public function rollback(): void
    {
        if ($this->connection && $this->inTransaction) {
            oci_rollback($this->connection);
            $this->inTransaction = false;
        }
    }

    /**
     * Prépare et exécute une instruction avec bind variables
     */
    private function prepareAndExecute(string $sql, array $binds = [], int $mode = OCI_COMMIT_ON_SUCCESS)
    {
        $stmt = @oci_parse($this->connection, $sql);
        if (!$stmt) {
            $err = oci_error($this->connection);
            throw new OracleQueryException($err['message'] ?? 'Erreur de syntaxe SQL', $err['code'] ?? 0, $err['offset'] ?? null, $sql);
        }

        foreach ($binds as $key => $val) {
            $bindKey = str_starts_with($key, ':') ? $key : ':' . $key;
            // Gestion par valeur/référence OCI8
            oci_bind_by_name($stmt, $bindKey, $binds[$key]);
        }

        $res = @oci_execute($stmt, $mode);
        if (!$res) {
            $err = oci_error($stmt);
            oci_free_statement($stmt);
            throw new OracleQueryException($err['message'] ?? 'Erreur d\'exécution SQL', $err['code'] ?? 0, $err['offset'] ?? null, $sql);
        }

        return $stmt;
    }

    public function close(): void
    {
        if ($this->connection) {
            @oci_close($this->connection);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

