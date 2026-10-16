<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Codes à usage unique basés sur le temps (TOTP, RFC 6238 : HMAC-SHA1, 6 chiffres, pas de 30 s), compatibles
 * avec les applications d'authentification courantes. Implémentation sans dépendance externe.
 */
final class Totp
{
    public const PERIODE = 30;

    public const CHIFFRES = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret aléatoire encodé en base32 (20 octets = 160 bits, comme recommandé par la RFC 4226). */
    public static function genererSecret(int $octets = 20): string
    {
        return self::base32Encoder(random_bytes($octets));
    }

    /** Pas de temps courant (nombre de périodes de 30 s depuis l'époque Unix). */
    public static function pas(?int $horodatage = null): int
    {
        // Horloge de l'application (Carbon) : les tests peuvent avancer le temps.
        return intdiv($horodatage ?? Carbon::now()->getTimestamp(), self::PERIODE);
    }

    /** Code d'un pas de temps (HOTP de la RFC 4226 appliqué au pas). */
    public static function code(string $secret, int $pas): string
    {
        $hmac = hash_hmac('sha1', pack('N2', intdiv($pas, 2 ** 32), $pas % 2 ** 32), self::base32Decoder($secret), true);
        $decalage = ord($hmac[19]) & 0x0F;
        $valeur = ((ord($hmac[$decalage]) & 0x7F) << 24)
            | (ord($hmac[$decalage + 1]) << 16)
            | (ord($hmac[$decalage + 2]) << 8)
            | ord($hmac[$decalage + 3]);

        return str_pad((string) ($valeur % 10 ** self::CHIFFRES), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code dans une fenêtre de ±$fenetre pas (décalage d'horloge du téléphone). Refuse les pas
     * déjà utilisés (≤ $dernierPas) pour empêcher le rejeu. Renvoie le pas accepté, sinon null.
     */
    public static function verifier(string $secret, string $code, ?int $dernierPas = null, int $fenetre = 1, ?int $horodatage = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::CHIFFRES.'}$/', $code)) {
            return null;
        }

        $courant = self::pas($horodatage);
        for ($pas = $courant - $fenetre; $pas <= $courant + $fenetre; $pas++) {
            if (($dernierPas === null || $pas > $dernierPas) && hash_equals(self::code($secret, $pas), $code)) {
                return $pas;
            }
        }

        return null;
    }

    /** Adresse otpauth:// à afficher en QR code dans l'application d'authentification. */
    public static function uri(string $secret, string $compte, string $emetteur): string
    {
        $libelle = rawurlencode($emetteur).':'.rawurlencode($compte);

        return "otpauth://totp/{$libelle}?".http_build_query([
            'secret' => $secret,
            'issuer' => $emetteur,
            'algorithm' => 'SHA1',
            'digits' => self::CHIFFRES,
            'period' => self::PERIODE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function base32Encoder(string $octets): string
    {
        $bits = '';
        foreach (str_split($octets) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }

        $resultat = '';
        foreach (str_split($bits, 5) as $groupe) {
            $resultat .= self::ALPHABET[bindec(str_pad($groupe, 5, '0'))];
        }

        return $resultat;
    }

    private static function base32Decoder(string $texte): string
    {
        $texte = strtoupper(preg_replace('/[\s=-]+/', '', $texte));
        $bits = '';
        foreach (str_split($texte) as $caractere) {
            $position = strpos(self::ALPHABET, $caractere);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        $resultat = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $resultat .= chr(bindec($octet));
            }
        }

        return $resultat;
    }
}
