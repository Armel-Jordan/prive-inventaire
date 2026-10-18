<?php

namespace App\Support;

/**
 * Codes-barres : types reconnus, détection du type d'un code et contrôle de sa clé.
 * - EAN-13 (13 chiffres), EAN-8 (8 chiffres), UPC-A (12 chiffres) : la dernière position est la clé de contrôle
 *   (somme pondérée 3/1 depuis la droite, modulo 10) ;
 * - Code 128 : 1 à 48 caractères ASCII imprimables ;
 * - interne : tout code propre à l'entreprise (1 à 50 caractères, sans espace aux extrémités).
 */
final class CodesBarres
{
    public const TYPES = ['ean13', 'ean8', 'upca', 'code128', 'interne'];

    /** Type d'un code : EAN-13, UPC-A ou EAN-8 si ses chiffres et sa clé le permettent, sinon Code 128. */
    public static function detecter(string $code): string
    {
        $code = trim($code);
        foreach ([13 => 'ean13', 12 => 'upca', 8 => 'ean8'] as $longueur => $type) {
            if (strlen($code) === $longueur && ctype_digit($code) && self::cleValide($code)) {
                return $type;
            }
        }

        return 'code128';
    }

    /** Message d'erreur si le code ne convient pas à son type, null s'il convient. */
    public static function erreur(string $code, string $type): ?string
    {
        $longueurs = ['ean13' => 13, 'ean8' => 8, 'upca' => 12];
        if (isset($longueurs[$type])) {
            $nom = ['ean13' => 'EAN-13', 'ean8' => 'EAN-8', 'upca' => 'UPC-A'][$type];
            if (strlen($code) !== $longueurs[$type] || ! ctype_digit($code)) {
                return __('Un code :nom compte :chiffres chiffres.', ['nom' => $nom, 'chiffres' => $longueurs[$type]]);
            }
            if (! self::cleValide($code)) {
                return __('La clé de contrôle du code :nom est fausse : le dernier chiffre devrait être :cle.', ['nom' => $nom, 'cle' => self::cle(substr($code, 0, -1))]);
            }

            return null;
        }
        if ($type === 'code128' && ! preg_match('/^[\x20-\x7E]{1,48}$/', $code)) {
            return __('Un code 128 compte de 1 à 48 caractères imprimables, sans accent.');
        }

        return null;
    }

    /** Clé de contrôle des chiffres d'un EAN ou d'un UPC (sans la clé). */
    public static function cle(string $chiffres): int
    {
        $somme = 0;
        foreach (array_reverse(str_split($chiffres)) as $rang => $chiffre) {
            $somme += (int) $chiffre * ($rang % 2 === 0 ? 3 : 1);
        }

        return (10 - $somme % 10) % 10;
    }

    public static function cleValide(string $code): bool
    {
        return self::cle(substr($code, 0, -1)) === (int) substr($code, -1);
    }
}
