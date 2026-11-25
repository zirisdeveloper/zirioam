<?php
namespace App\Services;

/**
 * Service d'Authentification et de Gestion des Administrateurs ZIRI OAM
 * Référentiel JSON : /etc/zirioam/auth.env (en production Linux) ou config/auth.env (local)
 * Chiffrement : config/crypto.php -> zirioam_encrypt_admin_password()
 */
class AdminAuthService
{
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $configured = function_exists('zirioam_config') ? zirioam_config('auth_env_path') : null;
        if (!empty($filePath)) {
            $this->filePath = $filePath;
        } elseif (!empty($configured)) {
            $this->filePath = (string)$configured;
        } else {
            $root = defined('ZIRIOAM_ROOT_PATH') ? ZIRIOAM_ROOT_PATH : dirname(__DIR__, 2);
            $this->filePath = DIRECTORY_SEPARATOR === '/'
                ? '/etc/zirioam/auth.env'
                : $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auth.env';
        }

        $cryptoFile = (defined('ZIRIOAM_ROOT_PATH') ? ZIRIOAM_ROOT_PATH : dirname(__DIR__, 2))
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'crypto.php';
        if (file_exists($cryptoFile)) {
            require_once $cryptoFile;
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Charge la liste des administrateurs depuis auth.env
     * @return array<int, array<string, string>>
     */
    public function loadAdmins(): array
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException(
                "Fichier d'authentification introuvable : {$this->filePath}. " .
                "Veuillez copier config/auth.env vers {$this->filePath}."
            );
        }

        if (!is_readable($this->filePath)) {
            throw new \RuntimeException(
                "Permissions insuffisantes pour lire le fichier d'authentification : {$this->filePath}."
            );
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw new \RuntimeException("Impossible de lire {$this->filePath}.");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['admins']) || !is_array($data['admins'])) {
            throw new \RuntimeException(
                "Format JSON invalide dans {$this->filePath} : clé racine 'admins' requise."
            );
        }

        return $data['admins'];
    }

    /**
     * Renvoie uniquement les noms d'utilisateurs des administrateurs (sans exposer les empreintes chiffrées)
     * @return array<int, string>
     */
    public function listAdminUsernames(): array
    {
        try {
            $admins = $this->loadAdmins();
        } catch (\Throwable $e) {
            return [];
        }

        $usernames = [];
        foreach ($admins as $admin) {
            if (!empty($admin['username'])) {
                $usernames[] = strtoupper(trim((string)$admin['username']));
            }
        }
        sort($usernames);
        return array_values(array_unique($usernames));
    }

    /**
     * Authentifie un administrateur en chiffrant le mot de passe saisi
     * et en le comparant avec le mot de passe chiffré stocké dans auth.env.
     */
    public function authenticate(string $username, string $password): array
    {
        $normUser = strtoupper(trim($username));
        if ($normUser === '' || $password === '') {
            throw new \InvalidArgumentException("Veuillez saisir votre nom d'utilisateur et votre mot de passe.");
        }

        $admins = $this->loadAdmins();
        $encryptedInput = zirioam_encrypt_admin_password($password);

        foreach ($admins as $admin) {
            $storedUser = strtoupper(trim((string)($admin['username'] ?? '')));
            $storedPass = (string)($admin['password'] ?? '');

            if ($storedUser !== '' && $storedUser === $normUser) {
                if ($storedPass !== '' && hash_equals($storedPass, $encryptedInput)) {
                    return [
                        'username' => $storedUser,
                    ];
                }
                break;
            }
        }

        throw new \InvalidArgumentException("Nom d'utilisateur ou mot de passe administrateur incorrect.");
    }

    /**
     * Modifie le mot de passe de l'administrateur actuellement connecté
     */
    public function changeOwnPassword(
        string $username,
        string $currentPassword,
        string $newPassword,
        string $confirmPassword
    ): void {
        // Vérifier l'ancien mot de passe
        $this->authenticate($username, $currentPassword);

        if ($newPassword === $currentPassword) {
            throw new \InvalidArgumentException("Le nouveau mot de passe doit être différent du mot de passe actuel.");
        }

        $this->validateNewPassword($newPassword, $confirmPassword);

        $normUser = strtoupper(trim($username));
        $admins = $this->loadAdmins();
        $updated = false;
        $now = gmdate('c');

        foreach ($admins as &$admin) {
            if (strtoupper(trim((string)($admin['username'] ?? ''))) === $normUser) {
                $admin['username'] = $normUser;
                $admin['password'] = zirioam_encrypt_admin_password($newPassword);
                $admin['updated_at'] = $now;
                $updated = true;
                break;
            }
        }
        unset($admin);

        if (!$updated) {
            throw new \RuntimeException("Compte administrateur '{$normUser}' introuvable dans le fichier d'authentification.");
        }

        $this->saveAdmins($admins);
    }

    /**
     * Crée un nouvel administrateur dans le fichier auth.env
     */
    public function createAdmin(string $newUsername, string $password, string $confirmPassword): string
    {
        $normUser = strtoupper(trim($newUsername));
        if (!preg_match('/^[A-Z0-9_.-]{3,50}$/', $normUser)) {
            throw new \InvalidArgumentException(
                "Identifiant administrateur invalide : utilisez 3 à 50 caractères alphanumériques (lettres, chiffres, _, ., -)."
            );
        }

        $this->validateNewPassword($password, $confirmPassword);

        $admins = $this->loadAdmins();
        foreach ($admins as $admin) {
            if (strtoupper(trim((string)($admin['username'] ?? ''))) === $normUser) {
                throw new \InvalidArgumentException("L'administrateur '{$normUser}' existe déjà.");
            }
        }

        $now = gmdate('c');
        $admins[] = [
            'username' => $normUser,
            'password' => zirioam_encrypt_admin_password($password),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->saveAdmins($admins);
        return $normUser;
    }

    /**
     * Réinitialise le mot de passe d'un administrateur existant
     */
    public function resetAdminPassword(string $targetUsername, string $newPassword, string $confirmPassword): string
    {
        $normTarget = strtoupper(trim($targetUsername));
        if ($normTarget === '') {
            throw new \InvalidArgumentException("Veuillez sélectionner l'administrateur cible.");
        }

        $this->validateNewPassword($newPassword, $confirmPassword);

        $admins = $this->loadAdmins();
        $found = false;
        $now = gmdate('c');

        foreach ($admins as &$admin) {
            if (strtoupper(trim((string)($admin['username'] ?? ''))) === $normTarget) {
                $admin['username'] = $normTarget;
                $admin['password'] = zirioam_encrypt_admin_password($newPassword);
                $admin['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        unset($admin);

        if (!$found) {
            throw new \InvalidArgumentException("L'administrateur '{$normTarget}' est introuvable dans {$this->filePath}.");
        }

        $this->saveAdmins($admins);
        return $normTarget;
    }

    private function validateNewPassword(string $password, string $confirmPassword): void
    {
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException("Le mot de passe doit contenir au moins 6 caractères.");
        }

        if ($password !== $confirmPassword) {
            throw new \InvalidArgumentException("Le mot de passe et sa confirmation ne correspondent pas.");
        }
    }

    private function saveAdmins(array $admins): void
    {
        if (file_exists($this->filePath) && !is_writable($this->filePath)) {
            throw new \RuntimeException(
                "Impossible d'écrire dans {$this->filePath} (permission refusée). " .
                "Sur Linux, exécutez : sudo chown apache:apache {$this->filePath} && sudo chmod 600 {$this->filePath}"
            );
        }

        $payload = json_encode(['admins' => array_values($admins)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if ($payload === false) {
            throw new \RuntimeException("Échec de l'encodage JSON des administrateurs.");
        }

        $bytes = @file_put_contents($this->filePath, $payload, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException(
                "Échec d'écriture dans {$this->filePath}. Vérifiez les permissions du fichier."
            );
        }
    }
}
