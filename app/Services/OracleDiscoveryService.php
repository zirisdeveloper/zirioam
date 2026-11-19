<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service de Découverte Automatique Oracle 19c (CDB / PDB / Version / Schémas)
 */
class OracleDiscoveryService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Découvre les informations globales de la base de données
     */
    public function discoverDatabaseInfo(): array
    {
        // Version Oracle
        $versionRow = $this->db->queryOne("SELECT BANNER_FULL FROM V\$VERSION WHERE ROWNUM = 1");
        $banner = $versionRow['BANNER_FULL'] ?? 'Oracle Database 19c';

        // Informations V$DATABASE
        $dbRow = $this->db->queryOne("SELECT NAME, CDB, OPEN_MODE, DATABASE_ROLE, CON_ID FROM V\$DATABASE");

        // Conteneur courant
        $conRow = $this->db->queryOne("SELECT SYS_CONTEXT('USERENV', 'CON_NAME') AS CON_NAME, SYS_CONTEXT('USERENV', 'CON_ID') AS CON_ID FROM DUAL");

        return [
            'banner' => $banner,
            'db_name' => $dbRow['NAME'] ?? 'UNKNOWN',
            'is_cdb' => ($dbRow['CDB'] ?? 'NO') === 'YES',
            'open_mode' => $dbRow['OPEN_MODE'] ?? 'UNKNOWN',
            'role' => $dbRow['DATABASE_ROLE'] ?? 'PRIMARY',
            'current_container' => $conRow['CON_NAME'] ?? 'CDB$ROOT',
            'current_con_id' => (int)($conRow['CON_ID'] ?? 1),
        ];
    }

    /**
     * Découvre la liste complète des PDBs et leurs états
     */
    public function discoverPdbs(): array
    {
        $sql = "SELECT CON_ID, NAME, OPEN_MODE, RESTRICTED FROM V\$PDBS ORDER BY NAME";
        return $this->db->query($sql);
    }

    /**
     * Découvre la liste des conteneurs (CDB$ROOT, PDB$SEED et toutes les PDBs)
     */
    public function discoverContainers(): array
    {
        $sql = "SELECT CON_ID, NAME, OPEN_MODE FROM V\$CONTAINERS ORDER BY CON_ID";
        return $this->db->query($sql);
    }

    /**
     * Découvre les schémas applicatifs (non maintenus par Oracle) dans le conteneur courant
     */
    public function discoverApplicationSchemas(): array
    {
        $sql = "SELECT USERNAME, ACCOUNT_STATUS, DEFAULT_TABLESPACE, CREATED 
                FROM DBA_USERS 
                WHERE ORACLE_MAINTAINED = 'N'
                  AND USERNAME NOT LIKE 'C##%'
                  AND USERNAME NOT LIKE 'ZIRIOAM%'
                ORDER BY USERNAME";
        return $this->db->query($sql);
    }

    /**
     * Découvre les comptes communs développeurs existants dans la CDB
     */
    public function discoverCommonDevelopers(): array
    {
        $sql = "SELECT USERNAME, ACCOUNT_STATUS, CREATED 
                FROM CDB_USERS 
                WHERE COMMON = 'YES' 
                  AND USERNAME LIKE 'C##%'
                  AND USERNAME NOT LIKE 'C##ZIRIOAM%'
                ORDER BY USERNAME";
        return $this->db->query($sql);
    }
}

