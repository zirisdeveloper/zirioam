<?php
namespace App\Services;

use App\Core\App;
use App\Security\Validator;

/**
 * Service de Gestion du Référentiel des Serveurs Oracle (/etc/zirioam/servers.json)
 */
class ServerRegistryService
{
    private string $filePath;
    private ?array $cachedServers = null;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?: (string)zirioam_config('servers_json_path');
    }

    /**
     * Charge et valide l'ensemble des serveurs autorisés
     */
    public function getServers(bool $onlyActive = true): array
    {
        if ($this->cachedServers !== null) {
            return $this->filterActive($this->cachedServers, $onlyActive);
        }

        if (!file_exists($this->filePath)) {
            throw new \RuntimeException(
                "Le fichier de configuration des serveurs Oracle est introuvable à l'emplacement spécifié : {$this->filePath}. " .
                "Veuillez créer ce fichier ou copier config/servers.example.json vers cet emplacement."
            );
        }

        if (!is_readable($this->filePath)) {
            throw new \RuntimeException(
                "Permissions insuffisantes pour lire le fichier des serveurs : {$this->filePath}."
            );
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le contenu du fichier : {$this->filePath}.");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Syntaxe JSON invalide dans {$this->filePath} : " . json_last_error_msg());
        }

        $errors = Validator::validateServersJson($data);
        if (!empty($errors)) {
            throw new \RuntimeException("Validation échouée pour {$this->filePath} : " . implode(' | ', $errors));
        }

        $this->cachedServers = $data['servers'];
        return $this->filterActive($this->cachedServers, $onlyActive);
    }

    /**
     * Recherche un serveur par son identifiant unique strict
     */
    public function getServerById(string $serverId): ?array
    {
        $servers = $this->getServers(false);
        foreach ($servers as $server) {
            if ($server['server_id'] === $serverId) {
                return $server;
            }
        }
        return null;
    }

    private function filterActive(array $servers, bool $onlyActive): array
    {
        if (!$onlyActive) {
            return $servers;
        }

        return array_values(array_filter($servers, function ($server) {
            return !isset($server['active']) || $server['active'] === true;
        }));
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}

