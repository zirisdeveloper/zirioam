<?php
namespace App\Services;

use App\Database\OracleConnection;

/**
 * Service d'Exportation JSON des Développeurs (Section 14.1)
 */
class DeveloperExportService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Génère l'export JSON strictement conforme
     * AUCUN mot de passe, secret ou privilège n'est exporté
     */
    public function generateExport(string $serverName): array
    {
        $sql = "SELECT USERNAME, FULL_NAME, EMAIL, STATUS 
                FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                ORDER BY USERNAME ASC";
        
        $rows = $this->db->query($sql);

        $developers = [];
        foreach ($rows as $row) {
            $developers[] = [
                'username' => $row['USERNAME'],
                'full_name' => $row['FULL_NAME'],
                'email' => $row['EMAIL'],
                'status' => $row['STATUS'] ?? 'ACTIVE',
            ];
        }

        return [
            'format' => 'ZIRIOAM_DEVELOPERS',
            'format_version' => '1.0',
            'export_date' => date('c'),
            'source_server' => $serverName,
            'total_count' => count($developers),
            'developers' => $developers,
        ];
    }
}

