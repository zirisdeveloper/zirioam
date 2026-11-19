<?php
namespace App\Core;

/**
 * Gestionnaire de Session Sécurisée pour ZIRIOAM
 */
class Session
{
    private bool $started = false;
    private array $flashData = [];

    public function __construct(array $config = [])
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = $config['lifetime'] ?? 14400;
            $path = $config['path'] ?? '/';
            $domain = $config['domain'] ?? '';
            $secure = $config['secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $httponly = $config['httponly'] ?? true;
            $samesite = $config['samesite'] ?? 'Strict';

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');

            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);

            if (!empty($config['name'])) {
                session_name($config['name']);
            }

            session_start();
        }

        $this->started = true;
        $this->loadFlash();
    }

    private function loadFlash(): void
    {
        if (isset($_SESSION['_zirioam_flash'])) {
            $this->flashData = $_SESSION['_zirioam_flash'];
            unset($_SESSION['_zirioam_flash']);
        }
    }

    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        session_regenerate_id($deleteOldSession);
    }

    public function getCsrfToken(): string
    {
        if (empty($_SESSION['_zirioam_csrf_token'])) {
            $_SESSION['_zirioam_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_zirioam_csrf_token'];
    }

    public function validateCsrfToken(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['_zirioam_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_zirioam_csrf_token'], $token);
    }

    public function setFlash(string $type, $message): void
    {
        if (!isset($_SESSION['_zirioam_flash'])) {
            $_SESSION['_zirioam_flash'] = [];
        }
        $_SESSION['_zirioam_flash'][$type][] = $message;
    }

    public function getFlash(?string $type = null)
    {
        if ($type === null) {
            return $this->flashData;
        }
        return $this->flashData[$type] ?? [];
    }
}

