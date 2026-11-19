<?php
namespace App\Database;

/**
 * Exception Spécifique pour les Erreurs Oracle OCI8
 */
class OracleQueryException extends \RuntimeException
{
    private ?int $oracleCode;
    private ?int $offset;
    private ?string $sql;

    public function __construct(string $message, ?int $code = 0, ?int $offset = null, ?string $sql = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, (int)$code, $previous);
        $this->oracleCode = $code;
        $this->offset = $offset;
        $this->sql = $sql;
    }

    public function getOracleCode(): ?int
    {
        return $this->oracleCode;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function getSql(): ?string
    {
        return $this->sql;
    }
}

