<?php
namespace App\Security;

/**
 * Validateur d'Entrées et de Données pour ZIRIOAM
 */
class Validator
{
    /**
     * Valide la structure stricte du fichier servers.json
     */
    public static function validateServersJson(array $data): array
    {
        $errors = [];

        if (!isset($data['servers']) || !is_array($data['servers'])) {
            $errors[] = "Le fichier JSON doit contenir une clé racine 'servers' de type tableau.";
            return $errors;
        }

        foreach ($data['servers'] as $idx => $server) {
            $prefix = "Serveur #{$idx} : ";
            
            if (empty($server['server_id']) || !is_string($server['server_id'])) {
                $errors[] = $prefix . "Le champ 'server_id' est obligatoire et doit être une chaîne.";
            }

            if (empty($server['server_name']) || !is_string($server['server_name'])) {
                $errors[] = $prefix . "Le champ 'server_name' est obligatoire.";
            }

            if (empty($server['ip_address']) || !self::isValidHost($server['ip_address'])) {
                $errors[] = $prefix . "Le champ 'ip_address' est obligatoire et doit être une adresse IP valide ou un nom d'hôte valide.";
            }

            if (!isset($server['port']) || !is_int($server['port']) || $server['port'] < 1 || $server['port'] > 65535) {
                $errors[] = $prefix . "Le champ 'port' doit être un entier valide (1-65535).";
            }

            if (isset($server['active']) && !is_bool($server['active'])) {
                $errors[] = $prefix . "Le champ 'active' doit être un booléen.";
            }

            if (isset($server['cdb_services']) && !is_array($server['cdb_services'])) {
                $errors[] = $prefix . "Le champ 'cdb_services' doit être un tableau.";
            }
        }

        return $errors;
    }

    /**
     * Valide le format JSON d'import des développeurs (ADD ONLY)
     */
    public static function validateDeveloperImportJson(array $data): array
    {
        $errors = [];

        if (($data['format'] ?? '') !== 'ZIRIOAM_DEVELOPERS') {
            $errors[] = "Le format du fichier est invalide. Attendu : 'ZIRIOAM_DEVELOPERS'.";
            return $errors;
        }

        if (($data['format_version'] ?? '') !== '1.0') {
            $errors[] = "Version de format non supportée. Attendue : '1.0'.";
            return $errors;
        }

        if (!isset($data['developers']) || !is_array($data['developers'])) {
            $errors[] = "Le fichier doit contenir un tableau 'developers'.";
            return $errors;
        }

        foreach ($data['developers'] as $idx => $dev) {
            $rowNum = $idx + 1;
            if (empty($dev['username']) || !is_string($dev['username'])) {
                $errors[] = "Ligne {$rowNum} : 'username' obligatoire.";
            } elseif (!zirioam_is_valid_oracle_identifier($dev['username'])) {
                $errors[] = "Ligne {$rowNum} : L'identifiant '{$dev['username']}' est invalide pour Oracle.";
            }

            if (empty($dev['full_name']) || !is_string($dev['full_name'])) {
                $errors[] = "Ligne {$rowNum} : 'full_name' obligatoire.";
            }

            if (empty($dev['email']) || !filter_var($dev['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Ligne {$rowNum} : 'email' invalide (" . ($dev['email'] ?? '') . ").";
            }

            if (isset($dev['status']) && !in_array($dev['status'], ['ACTIVE', 'INACTIVE'], true)) {
                $errors[] = "Ligne {$rowNum} : 'status' doit valoir 'ACTIVE' ou 'INACTIVE'.";
            }
        }

        return $errors;
    }

    public static function isValidHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $host);
    }
}

