<?php
/**
 * ZIRI OAM — Oracle Developer Access Manager
 * Helpers de sécurité, cryptographie et validation
 */

use App\Core\App;

// Polyfills de compatibilité PHP 8.0+ pour environnements de test
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('e')) {
    /**
     * Protection XSS : échappement des caractères HTML
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('zirioam_flash')) {
    /**
     * Récupère un message flash ou la liste de tous les messages flash
     */
    function zirioam_flash(?string $type = null)
    {
        $session = App::getInstance()->getSession();
        return $session->getFlash($type);
    }
}

if (!function_exists('zirioam_generate_secure_password')) {
    /**
     * Génère un mot de passe temporaire cryptographiquement sécurisé
     * Conforme aux exigences de syntaxe Oracle (commence par une lettre,
     * mélange majuscules, minuscules, chiffres et caractères autorisés #, _, $).
     */
    function zirioam_generate_secure_password(int $length = 14): string
    {
        if ($length < 12) {
            $length = 12;
        }

        $alphasUpper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $alphasLower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $specials = '#_$';

        // Le premier caractère DOIT être une lettre en Oracle sans guillemets doubles obligatoires
        $password = $alphasUpper[random_int(0, strlen($alphasUpper) - 1)];

        // Garantir au moins un caractère de chaque groupe
        $password .= $alphasLower[random_int(0, strlen($alphasLower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $specials[random_int(0, strlen($specials) - 1)];

        $allChars = $alphasUpper . $alphasLower . $digits . $specials;
        $allLen = strlen($allChars);

        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $allChars[random_int(0, $allLen - 1)];
        }

        // Mélanger les caractères après le premier caractère pour maintenir une lettre en première position
        $firstChar = $password[0];
        $rest = str_split(substr($password, 1));
        shuffle($rest);

        return $firstChar . implode('', $rest);
    }
}

if (!function_exists('zirioam_is_valid_oracle_identifier')) {
    /**
     * Valide la syntaxe d'un identifiant Oracle (nom d'utilisateur, schéma, table, rôle)
     * Règle Oracle 19c : commence par une lettre ou C##/c##, composé de [A-Z0-9_$#], max 128 caractères.
     */
    function zirioam_is_valid_oracle_identifier(string $identifier): bool
    {
        $identifier = trim($identifier);
        if (strlen($identifier) === 0 || strlen($identifier) > 128) {
            return false;
        }

        // Vérification contre injection SQL : identifiant strict
        return (bool)preg_match('/^(?:C##|c##)?[A-Za-z][A-Za-z0-9_$#]{0,127}$/', $identifier);
    }
}

if (!function_exists('zirioam_normalize_identifier')) {
    /**
     * Normalise un identifiant Oracle (majuscules, suppression espaces superflus)
     */
    function zirioam_normalize_identifier(string $identifier): string
    {
        return strtoupper(trim($identifier));
    }
}

if (!function_exists('zirioam_validate_oracle_password')) {
    /**
     * Valide les critères de complexité minimale d'un mot de passe pour Oracle 19c
     * @return array<string> Liste des erreurs s'il y en a
     */
    function zirioam_validate_oracle_password(string $pwd): array
    {
        $errors = [];
        if (strlen($pwd) < 8) {
            $errors[] = "Le mot de passe doit comporter au moins 8 caractères.";
        }
        if (strlen($pwd) > 30) {
            $errors[] = "Le mot de passe ne doit pas dépasser 30 caractères.";
        }
        if (!preg_match('/[A-Z]/', $pwd)) {
            $errors[] = "Le mot de passe doit contenir au moins une lettre majuscule.";
        }
        if (!preg_match('/[a-z]/', $pwd)) {
            $errors[] = "Le mot de passe doit contenir au moins une lettre minuscule.";
        }
        if (!preg_match('/[0-9]/', $pwd)) {
            $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
        }
        if (strpos($pwd, '"') !== false) {
            $errors[] = "Le caractère guillemet double (\") n'est pas autorisé dans le mot de passe.";
        }
        return $errors;
    }
}

