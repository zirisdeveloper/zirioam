<?php
namespace App\Services;

use App\Database\OracleConnection;
use App\Security\Validator;

/**
 * Service d'Importation JSON des Développeurs avec Règle Absolue ADD ONLY (Section 14.2 & 14.3)
 */
class DeveloperImportService
{
    private OracleConnection $db;

    public function __construct(OracleConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Analyse un contenu JSON d'importation et prépare la séparation Nouveaux / Déjà Existants
     */
    public function analyzeImport(string $jsonContent): array
    {
        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Le fichier fourni n'est pas un document JSON valide : " . json_last_error_msg());
        }

        // Validation stricte du schéma
        $errors = Validator::validateDeveloperImportJson($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException("Erreurs de validation du format JSON : " . implode(' | ', $errors));
        }

        // Récupération de tous les identifiants déjà présents dans la base cible
        $existingRows = $this->db->query("SELECT USERNAME FROM C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS");
        $existingMap = [];
        foreach ($existingRows as $row) {
            $existingMap[strtoupper(trim($row['USERNAME']))] = true;
        }

        $seenInFile = [];
        $existingInDb = [];
        $newToImport = [];

        foreach ($data['developers'] as $dev) {
            $rawUser = trim($dev['username']);
            $normUser = strtoupper($rawUser);
            if (str_starts_with($normUser, 'C##')) {
                $normUser = substr($normUser, 3);
            }

            // Détection des doublons internes au fichier lui-même
            if (isset($seenInFile[$normUser])) {
                continue; // Ignorer les doublons du fichier
            }
            $seenInFile[$normUser] = true;

            $normalizedDev = [
                'username' => $normUser,
                'oracle_user' => 'C##' . $normUser,
                'full_name' => trim($dev['full_name']),
                'email' => strtolower(trim($dev['email'])),
                'status' => in_array(strtoupper(trim($dev['status'] ?? '')), ['ACTIVE', 'INACTIVE']) ? strtoupper(trim($dev['status'])) : 'ACTIVE',
            ];

            if (isset($existingMap[$normUser])) {
                $existingInDb[] = $normalizedDev;
            } else {
                $newToImport[] = $normalizedDev;
            }
        }

        // Construction du message requis par le cahier des charges
        $warningMessage = null;
        if (!empty($existingInDb)) {
            $names = array_column($existingInDb, 'username');
            if (count($names) <= 5) {
                $namesStr = implode(', ', $names);
            } else {
                $namesStr = implode(', ', array_slice($names, 0, 5)) . " et " . (count($names) - 5) . " autres";
            }
            $warningMessage = "Les développeurs {$namesStr} existent déjà sur ce serveur. Ils ne seront pas modifiés.";
        }

        return [
            'total_in_file' => count($seenInFile),
            'existing_count' => count($existingInDb),
            'new_count' => count($newToImport),
            'existing_developers' => $existingInDb,
            'new_developers' => $newToImport,
            'warning_message' => $warningMessage,
            'source_server' => $data['source_server'] ?? 'NON_SPÉCIFIÉ',
            'export_date' => $data['export_date'] ?? 'N/A',
        ];
    }

    /**
     * Exécute l'insertion exclusive des NOUVEAUX développeurs (ADD ONLY)
     * AUCUN compte Oracle n'est créé automatiquement
     * AUCUN privilège n'est attribué automatiquement
     */
    public function executeAddOnlyImport(array $newDevelopers, string $dbaUser, ?string $clientIp = null): array
    {
        $importedCount = 0;
        $errors = [];

        $insertSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
                      (USERNAME, ORACLE_USER, FULL_NAME, EMAIL, STATUS, CREATED_BY, CREATED_AT, UPDATED_AT)
                      VALUES 
                      (:user, :ora, :name, :email, :status, :dba, SYSTIMESTAMP, SYSTIMESTAMP)";

        foreach ($newDevelopers as $dev) {
            try {
                $this->db->execute($insertSql, [
                    'user' => $dev['username'],
                    'ora' => $dev['oracle_user'],
                    'name' => $dev['full_name'],
                    'email' => $dev['email'],
                    'status' => $dev['status'],
                    'dba' => $dbaUser,
                ]);
                $importedCount++;
            } catch (\Throwable $e) {
                // En cas de contrainte unique concurrente, ignorer conformément à ADD ONLY
                $errors[] = "Erreur sur {$dev['username']} : " . $e->getMessage();
            }
        }

        // Audit de l'opération d'import
        try {
            $auditSql = "INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT 
                         (EVENT_TIME, DBA_USER, ACTION_TYPE, DETAILS, STATUS, CLIENT_IP)
                         VALUES (SYSTIMESTAMP, :dba, 'JSON_IMPORT_ADD_ONLY', :details, 'SUCCESS', :ip)";
            $this->db->execute($auditSql, [
                'dba' => $dbaUser,
                'details' => "Importation JSON terminée. Nouveaux ajoutés: {$importedCount}. Erreurs: " . count($errors),
                'ip' => $clientIp,
            ]);
        } catch (\Throwable $e) {
            // Ignorer
        }

        return [
            'imported_count' => $importedCount,
            'errors' => $errors,
        ];
    }
}

