<?php
namespace App\Services;

use App\Core\App;
use App\Database\OracleConnection;
use App\Database\OracleQueryException;
use App\Security\EncryptionService;

/**
 * Service de Gestion du Cycle de Vie des Connexions Oracle
 */
class OracleConnectionService
{
    private static ?OracleConnection $activeConnection = null;

    /**
     * Tente d'authentifier un DBA et établit la session Oracle
     */
    public function authenticate(
        array $server,
        string $serviceName,
        string $username,
        string $password,
        string $mode
    ): OracleConnection {
        $username = trim($username);
        $upperUser = strtoupper($username);
        $mode = strtoupper(trim($mode));

        // RÈGLE ABSOLUE : SYS doit obligatoirement être utilisé en mode SYSDBA
        if ($upperUser === 'SYS' && $mode !== 'SYSDBA') {
            throw new \InvalidArgumentException(
                "Conformément aux règles de sécurité Oracle, l'utilisateur SYS doit obligatoirement être utilisé avec le mode SYSDBA."
            );
        }

        // RÈGLE ABSOLUE : SYSTEM doit être utilisé en mode NORMAL
        if ($upperUser === 'SYSTEM' && $mode === 'SYSDBA') {
            throw new \InvalidArgumentException(
                "L'utilisateur SYSTEM doit être utilisé en mode Connexion Normale (pas en SYSDBA)."
            );
        }

        if ($mode !== 'NORMAL' && $mode !== 'SYSDBA') {
            throw new \InvalidArgumentException("Mode de connexion non reconnu : {$mode}.");
        }

        // Tenter la connexion OCI8
        $connection = new OracleConnection(
            $server['ip_address'],
            (int)$server['port'],
            $serviceName,
            $username,
            $password,
            $mode
        );

        // Connexion réussie : sauvegarder les métadonnées et identifiants chiffrés en session
        $session = App::getInstance()->getSession();
        
        $sessionPayload = json_encode([
            'server_id' => $server['server_id'],
            'server_name' => $server['server_name'],
            'ip_address' => $server['ip_address'],
            'port' => (int)$server['port'],
            'service' => $serviceName,
            'username' => $username,
            'password' => $password,
            'mode' => $mode,
            'authenticated_at' => time(),
        ]);

        $encryptedPayload = EncryptionService::encrypt($sessionPayload);

        $session->set('oracle_session', $encryptedPayload);
        $session->set('current_server', $server);
        $session->set('current_service', $serviceName);
        $session->set('current_user', $username);
        $session->set('current_mode', $mode);

        self::$activeConnection = $connection;
        return $connection;
    }

    /**
     * Récupère la connexion Oracle active pour la requête courante
     */
    public function getActiveConnection(): ?OracleConnection
    {
        if (self::$activeConnection !== null) {
            return self::$activeConnection;
        }

        $session = App::getInstance()->getSession();
        $encryptedPayload = $session->get('oracle_session');

        if (!$encryptedPayload) {
            return null;
        }

        $json = EncryptionService::decrypt($encryptedPayload);
        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if (!$data || !is_array($data)) {
            return null;
        }

        try {
            self::$activeConnection = new OracleConnection(
                $data['ip_address'],
                (int)$data['port'],
                $data['service'],
                $data['username'],
                $data['password'],
                $data['mode']
            );
            return self::$activeConnection;
        } catch (\Throwable $e) {
            // En cas d'échec de reconnexion, invalider la session
            $this->disconnect();
            return null;
        }
    }

    /**
     * Déconnecte et détruit la session Oracle
     */
    public function disconnect(): void
    {
        if (self::$activeConnection) {
            self::$activeConnection->close();
            self::$activeConnection = null;
        }

        $session = App::getInstance()->getSession();
        $session->remove('oracle_session');
        $session->remove('current_server');
        $session->remove('current_service');
        $session->remove('current_user');
        $session->remove('current_mode');
        $session->remove('current_cdb');
        $session->remove('current_pdb');
        $session->remove('vault_user');
        $session->remove('vault_password');
    }

    /**
     * Crée une connexion directe avec des identifiants spécifiques (ex: C##voltmanager)
     */
    public function createCustomConnection(
        string $username,
        string $password,
        string $mode = 'NORMAL',
        ?string $serviceOverride = null
    ): ?OracleConnection {
        $session = App::getInstance()->getSession();
        $server = $session->get('current_server');
        $service = $serviceOverride ?: $session->get('current_service');

        if (!$server || !$service) {
            return null;
        }

        return new OracleConnection(
            $server['ip_address'],
            (int)$server['port'],
            $service,
            $username,
            $password,
            $mode
        );
    }

    /**
     * Retourne une connexion active avec le compte Vault Manager s'il est configuré en session
     */
    public function getVaultConnection(?string $serviceOverride = null): ?OracleConnection
    {
        $session = App::getInstance()->getSession();
        $vaultUser = (string)$session->get('vault_user', '');
        $vaultPassword = (string)$session->get('vault_password', '');

        if (empty($vaultUser) || empty($vaultPassword)) {
            return null;
        }

        try {
            return $this->createCustomConnection($vaultUser, $vaultPassword, 'NORMAL', $serviceOverride);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

