<?php
/**
 * ZIRI OAM — Fonction de Chiffrement des Mots de Passe Administrateurs
 *
 * Peut être utilisée :
 *  1) Par l'application web (via require_once)
 *  2) En ligne de commande sous Linux pour générer un mot de passe chiffré :
 *     php config/crypto.php "MonMotDePasse"
 */

if (!defined('ZIRIOAM_AUTH_CRYPTO_KEY')) {
    // Clé secrète applicative fixe (32 octets / 256 bits) pour chiffrement déterministe AES-256-CBC
    define('ZIRIOAM_AUTH_CRYPTO_KEY', hash('sha256', 'ZiriOam#2026!AdminAuth@CryptoKey_Oracle19c_Multitenant', true));
}

if (!function_exists('zirioam_encrypt_admin_password')) {
    /**
     * Chiffre un mot de passe administrateur de manière déterministe (AES-256-CBC + IV dérivé par HMAC-SHA256)
     * afin que le même mot de passe produise toujours exactement la même empreinte chiffrée.
     */
    function zirioam_encrypt_admin_password(string $plainText): string
    {
        if ($plainText === '') {
            throw new \InvalidArgumentException("Le mot de passe à chiffrer ne peut pas être vide.");
        }

        $key = ZIRIOAM_AUTH_CRYPTO_KEY;

        if (function_exists('openssl_encrypt')) {
            // IV déterministe (16 octets) dérivé du secret + mot de passe pour garantir unicité par mot de passe et reproductibilité
            $iv = substr(hash_hmac('sha256', $plainText, $key, true), 0, 16);
            $cipherRaw = openssl_encrypt(
                $plainText,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($cipherRaw === false) {
                throw new \RuntimeException("Échec du chiffrement OpenSSL AES-256-CBC.");
            }

            return base64_encode($iv . $cipherRaw);
        }

        // Repli HMAC-SHA256 si OpenSSL n'était pas chargé
        return base64_encode(hash_hmac('sha256', $plainText, $key, true));
    }
}

if (!function_exists('zirioam_decrypt_admin_password')) {
    /**
     * Déchiffre un mot de passe chiffré par zirioam_encrypt_admin_password()
     */
    function zirioam_decrypt_admin_password(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16 || !function_exists('openssl_decrypt')) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $cipherRaw = substr($raw, 16);

        $plainText = openssl_decrypt(
            $cipherRaw,
            'AES-256-CBC',
            ZIRIOAM_AUTH_CRYPTO_KEY,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plainText === false ? null : $plainText;
    }
}

// Exécution directe en ligne de commande (CLI Linux / Windows)
if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    $args = $_SERVER['argv'] ?? [];
    if (count($args) < 2 || $args[1] === '--help' || $args[1] === '-h') {
        fwrite(STDERR, "ZIRI OAM — Utilitaire de chiffrement de mot de passe administrateur\n");
        fwrite(STDERR, "Usage   : php " . basename(__FILE__) . " <mot_de_passe>\n");
        fwrite(STDERR, "Exemple : php " . basename(__FILE__) . " \"sond0509\"\n");
        exit((count($args) >= 2) ? 0 : 1);
    }

    try {
        echo zirioam_encrypt_admin_password((string)$args[1]) . PHP_EOL;
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Erreur : " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

