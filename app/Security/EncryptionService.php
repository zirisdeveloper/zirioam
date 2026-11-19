<?php
namespace App\Security;

/**
 * Service de Chiffrement Symétrique AES-256-GCM
 * Utilisé pour la protection des identifiants temporaires de session en mémoire
 */
class EncryptionService
{
    private static ?string $runtimeKey = null;

    /**
     * Obtient ou génère une clé éphémère liée au processus/session
     */
    private static function getKey(): string
    {
        if (self::$runtimeKey === null) {
            $keyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zirioam_runtime.key';
            if (file_exists($keyFile) && is_readable($keyFile)) {
                self::$runtimeKey = file_get_contents($keyFile);
            } else {
                self::$runtimeKey = random_bytes(32);
                @file_put_contents($keyFile, self::$runtimeKey);
                @chmod($keyFile, 0600);
            }
        }
        return self::$runtimeKey;
    }

    /**
     * Chiffre une chaîne sensible avec OpenSSL AES-256-GCM
     */
    public static function encrypt(string $plainText): string
    {
        $key = self::getKey();
        $iv = random_bytes(12); // 96-bit IV recommandé pour GCM
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipherText === false) {
            throw new \RuntimeException("Erreur lors du chiffrement des données.");
        }

        // Pack IV (12) + Tag (16) + CipherText
        return base64_encode($iv . $tag . $cipherText);
    }

    /**
     * Déchiffre une chaîne chiffrée
     */
    public static function decrypt(string $encodedData): ?string
    {
        $raw = base64_decode($encodedData, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $key = self::getKey();
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);

        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plainText === false ? null : $plainText;
    }
}

