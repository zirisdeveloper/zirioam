<?php
namespace Tests;

use App\Security\EncryptionService;

class SecurityValidatorTest
{
    public static function run(): void
    {
        echo "=== [TEST] Fonctions de Sécurité et Cryptographie ===\n";

        // 1. Validation des identifiants Oracle 19c
        assert(zirioam_is_valid_oracle_identifier('ALI'), "ALI doit être valide.");
        assert(zirioam_is_valid_oracle_identifier('C##ALI'), "C##ALI doit être valide.");
        assert(zirioam_is_valid_oracle_identifier('R_GESTION_READ'), "R_GESTION_READ doit être valide.");
        assert(!zirioam_is_valid_oracle_identifier('123BAD'), "Identifiant commençant par un chiffre doit être invalide.");
        assert(!zirioam_is_valid_oracle_identifier('SELECT * FROM USERS;'), "Injection SQL doit être rejetée.");
        assert(!zirioam_is_valid_oracle_identifier('USER;DROP TABLE T1;--'), "Injection avec commentaires doit être rejetée.");
        echo "  [PASS] Validation stricte des identifiants Oracle et rejet des injections SQL.\n";

        // 2. Génération de mots de passe temporaires cryptographiquement sûrs
        $pwd = zirioam_generate_secure_password(16);
        assert(strlen($pwd) === 16, "Longueur du mot de passe attendue : 16");
        assert(ctype_alpha($pwd[0]), "Le premier caractère doit obligatoirement être une lettre pour Oracle.");
        assert(preg_match('/[A-Z]/', $pwd), "Doit contenir au moins une majuscule.");
        assert(preg_match('/[a-z]/', $pwd), "Doit contenir au moins une minuscule.");
        assert(preg_match('/[0-9]/', $pwd), "Doit contenir au moins un chiffre.");
        assert(preg_match('/[#_\$]/', $pwd), "Doit contenir au moins un symbole Oracle autorisé (#, _, $).");
        echo "  [PASS] Générateur de mot de passe temporaire conforme aux spécifications Oracle.\n";

        // 3. Chiffrement symétrique AES-256-GCM
        $secretText = "MotDePasseConfidentielOracle19c#2026";
        $encrypted = EncryptionService::encrypt($secretText);
        assert(!empty($encrypted), "Le texte chiffré ne doit pas être vide.");
        assert($encrypted !== $secretText, "Le texte chiffré doit différer du texte en clair.");
        
        $decrypted = EncryptionService::decrypt($encrypted);
        assert($decrypted === $secretText, "Le déchiffrement AES-256-GCM doit restituer exactement le secret initial.");
        echo "  [PASS] Chiffrement/déchiffrement OpenSSL AES-256-GCM en mémoire validé.\n";
    }
}

