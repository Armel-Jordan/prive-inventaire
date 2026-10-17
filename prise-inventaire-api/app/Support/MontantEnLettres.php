<?php

namespace App\Support;

/**
 * Montant écrit en toutes lettres, en majuscules, pour un chèque : en français (orthographe traditionnelle : « vingt et
 * un », « soixante et onze », « quatre-vingts » et « deux cents » en fin de nombre ou devant million, invariables devant
 * mille, « deux millions de francs CFA ») ou en anglais. Les centimes s'écrivent en chiffres (« ET 50 CENTIMES ») ; une
 * devise sans centimes (franc CFA) est arrondie à l'unité.
 */
final class MontantEnLettres
{
    private const UNITES = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];

    private const DIZAINES = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    private const UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /** Noms des devises (singulier, pluriel, centime, centimes) et leurs décimales ; une devise absente garde son code. */
    private const DEVISES = [
        'XAF' => ['decimales' => 0, 'fr' => ['franc CFA', 'francs CFA'], 'en' => ['CFA franc', 'CFA francs']],
        'XOF' => ['decimales' => 0, 'fr' => ['franc CFA', 'francs CFA'], 'en' => ['CFA franc', 'CFA francs']],
        'EUR' => ['decimales' => 2, 'fr' => ['euro', 'euros', 'centime', 'centimes'], 'en' => ['euro', 'euros', 'cent', 'cents']],
        'USD' => ['decimales' => 2, 'fr' => ['dollar', 'dollars', 'cent', 'cents'], 'en' => ['dollar', 'dollars', 'cent', 'cents']],
        'CAD' => ['decimales' => 2, 'fr' => ['dollar', 'dollars', 'cent', 'cents'], 'en' => ['dollar', 'dollars', 'cent', 'cents']],
        'GBP' => ['decimales' => 2, 'fr' => ['livre', 'livres', 'penny', 'pence'], 'en' => ['pound', 'pounds', 'penny', 'pence']],
        'CHF' => ['decimales' => 2, 'fr' => ['franc suisse', 'francs suisses', 'centime', 'centimes'], 'en' => ['Swiss franc', 'Swiss francs', 'centime', 'centimes']],
    ];

    /** Décimales d'une devise : aucune pour le franc CFA, deux sinon. */
    public static function decimales(string $devise): int
    {
        return self::DEVISES[strtoupper($devise)]['decimales'] ?? 2;
    }

    public static function ecrire(float $montant, string $devise, string $langue = 'fr'): string
    {
        $anglais = $langue === 'en';
        $config = self::DEVISES[strtoupper($devise)] ?? null;
        $noms = $config[$anglais ? 'en' : 'fr'] ?? [strtoupper($devise), strtoupper($devise), $anglais ? 'cent' : 'centime', $anglais ? 'cents' : 'centimes'];
        if (self::decimales($devise) === 0) {
            $entier = (int) round(abs($montant));
            $centimes = 0;
        } else {
            $total = (int) round(abs($montant) * 100);
            $entier = intdiv($total, 100);
            $centimes = $total % 100;
        }

        $texte = ($anglais ? self::nombreEn($entier) : self::nombreFr($entier)).' ';
        $nom = $entier > 1 ? $noms[1] : $noms[0];
        // « deux millions de francs CFA », « un million d'euros ».
        if (! $anglais && $entier >= 1_000_000 && $entier % 1_000_000 === 0) {
            $texte .= preg_match('/^[aeiouyéèh]/iu', $nom) ? "d'" : 'de ';
        }
        $texte .= $nom;
        if ($centimes > 0) {
            $texte .= ($anglais ? ' and ' : ' et ').$centimes.' '.($centimes > 1 ? $noms[3] : $noms[2]);
        }

        return mb_strtoupper($texte);
    }

    public static function nombreFr(int $nombre): string
    {
        if ($nombre === 0) {
            return self::UNITES[0];
        }
        $milliards = intdiv($nombre, 1_000_000_000);
        $millions = intdiv($nombre % 1_000_000_000, 1_000_000);
        $milliers = intdiv($nombre % 1_000_000, 1000);
        $reste = $nombre % 1000;
        $mots = [];
        if ($milliards) {
            $mots[] = self::fr999($milliards, true).' milliard'.($milliards > 1 ? 's' : '');
        }
        if ($millions) {
            $mots[] = self::fr999($millions, true).' million'.($millions > 1 ? 's' : '');
        }
        if ($milliers) {
            // « mille » est invariable, et « cent » ou « quatre-vingt » devant lui aussi.
            $mots[] = $milliers === 1 ? 'mille' : self::fr999($milliers, false).' mille';
        }
        if ($reste) {
            $mots[] = self::fr999($reste, true);
        }

        return implode(' ', $mots);
    }

    public static function nombreEn(int $nombre): string
    {
        if ($nombre === 0) {
            return self::UNITS[0];
        }
        $mots = [];
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $valeur => $nom) {
            if ($nombre >= $valeur) {
                $mots[] = self::en999(intdiv($nombre, $valeur)).' '.$nom;
                $nombre %= $valeur;
            }
        }
        if ($nombre) {
            $mots[] = self::en999($nombre);
        }

        return implode(' ', $mots);
    }

    /** De 1 à 999 ; $final : rien ne suit, ou un nom (million, milliard) : « deux cents », « quatre-vingts ». */
    private static function fr999(int $nombre, bool $final): string
    {
        $centaines = intdiv($nombre, 100);
        $reste = $nombre % 100;
        $mots = [];
        if ($centaines) {
            $mots[] = ($centaines === 1 ? 'cent' : self::UNITES[$centaines].' cent').($centaines > 1 && $reste === 0 && $final ? 's' : '');
        }
        if ($reste) {
            $mots[] = $reste === 80 && ! $final ? 'quatre-vingt' : self::fr99($reste);
        }

        return implode(' ', $mots);
    }

    private static function fr99(int $nombre): string
    {
        if ($nombre <= 16) {
            return self::UNITES[$nombre];
        }
        if ($nombre < 20) {
            return 'dix-'.self::UNITES[$nombre - 10];
        }
        $dizaine = intdiv($nombre, 10);
        $unite = $nombre % 10;

        return match (true) {
            // 70 à 79 : soixante-dix, soixante et onze, soixante-douze…
            $dizaine === 7 => $unite === 1 ? 'soixante et onze' : 'soixante-'.self::fr99(10 + $unite),
            $dizaine === 8 => $unite === 0 ? 'quatre-vingts' : 'quatre-vingt-'.self::UNITES[$unite],
            $dizaine === 9 => 'quatre-vingt-'.self::fr99(10 + $unite),
            $unite === 0 => self::DIZAINES[$dizaine],
            $unite === 1 => self::DIZAINES[$dizaine].' et un',
            default => self::DIZAINES[$dizaine].'-'.self::UNITES[$unite],
        };
    }

    private static function en999(int $nombre): string
    {
        $centaines = intdiv($nombre, 100);
        $reste = $nombre % 100;
        $mots = [];
        if ($centaines) {
            $mots[] = self::UNITS[$centaines].' hundred';
        }
        if ($reste) {
            $mots[] = $reste < 20 ? self::UNITS[$reste] : self::TENS[intdiv($reste, 10)].($reste % 10 ? '-'.self::UNITS[$reste % 10] : '');
        }

        return implode(' ', $mots);
    }
}
