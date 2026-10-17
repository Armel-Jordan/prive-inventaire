<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Dessin d'un code-barres en SVG (étiquettes en PDF), sans bibliothèque :
 * - EAN-13 et UPC-A (un EAN-13 qui commence par 0), EAN-8 : gardes, chiffres de gauche en jeux L et G selon le premier
 *   chiffre, de droite en jeu R ;
 * - Code 128 : jeu C pour un nombre pair de chiffres (deux chiffres par symbole), jeu B sinon ; symbole de contrôle
 *   (somme pondérée modulo 103) et arrêt ;
 * - zones blanches de part et d'autre ; les barres en rectangles, mis à l'échelle par la vue.
 */
final class SvgCodeBarre
{
    /** Largeurs (barre, espace, barre…) des symboles 0 à 105 du Code 128, puis l'arrêt (106). */
    public const MOTIFS_128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Jeux des six chiffres de gauche d'un EAN-13, selon son premier chiffre. */
    private const PARITES = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** Le code en SVG : EAN si son type l'est (et sa clé juste), Code 128 sinon. */
    public static function svg(string $code, ?string $type = null, int $hauteur = 50): string
    {
        $type ??= CodesBarres::detecter($code);
        $modules = match ($type) {
            'ean13' => self::ean13($code),
            'upca' => self::ean13('0'.$code),
            'ean8' => self::ean8($code),
            default => self::code128($code),
        };
        // Zones blanches : 10 modules (Code 128), 9 à gauche et 7 à droite (EAN).
        [$avant, $apres] = in_array($type, ['ean13', 'upca', 'ean8'], true) ? [9, 7] : [10, 10];
        $largeur = $avant + strlen($modules) + $apres;
        $barres = '';
        foreach (self::barres($modules) as [$debut, $epaisseur]) {
            $barres .= '<rect x="'.($avant + $debut).'" y="0" width="'.$epaisseur.'" height="'.$hauteur.'"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$largeur.'" height="'.$hauteur.'" viewBox="0 0 '.$largeur.' '.$hauteur
            .'" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="'.$largeur.'" height="'.$hauteur.'" fill="#fff"/><g fill="#000">'.$barres.'</g></svg>';
    }

    /** L'image à placer dans une page : un SVG en data URI. */
    public static function dataUri(string $code, ?string $type = null): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($code, $type));
    }

    /** Les 95 modules d'un EAN-13 (1 : barre, 0 : espace). */
    public static function ean13(string $code): string
    {
        if (! preg_match('/^\d{13}$/', $code)) {
            throw new InvalidArgumentException('Un EAN-13 compte 13 chiffres.');
        }
        $parites = self::PARITES[(int) $code[0]];
        $modules = '101';
        for ($i = 1; $i <= 6; $i++) {
            $modules .= ($parites[$i - 1] === 'L' ? self::L : self::G)[(int) $code[$i]];
        }
        $modules .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $modules .= self::R[(int) $code[$i]];
        }

        return $modules.'101';
    }

    /** Les 67 modules d'un EAN-8. */
    public static function ean8(string $code): string
    {
        if (! preg_match('/^\d{8}$/', $code)) {
            throw new InvalidArgumentException('Un EAN-8 compte 8 chiffres.');
        }
        $modules = '101';
        for ($i = 0; $i < 4; $i++) {
            $modules .= self::L[(int) $code[$i]];
        }
        $modules .= '01010';
        for ($i = 4; $i < 8; $i++) {
            $modules .= self::R[(int) $code[$i]];
        }

        return $modules.'101';
    }

    /** Les valeurs des symboles d'un Code 128 : départ, données, contrôle, arrêt. */
    public static function symboles128(string $texte): array
    {
        if ($texte === '' || ! preg_match('/^[\x20-\x7E]+$/', $texte)) {
            throw new InvalidArgumentException('Un code 128 compte des caractères imprimables, sans accent.');
        }
        if (strlen($texte) % 2 === 0 && ctype_digit($texte)) {
            // Jeu C : deux chiffres par symbole.
            $valeurs = [105, ...array_map('intval', str_split($texte, 2))];
        } else {
            // Jeu B : un caractère par symbole, du code ASCII moins 32.
            $valeurs = [104, ...array_map(fn (string $c) => ord($c) - 32, str_split($texte))];
        }
        $somme = $valeurs[0];
        foreach (array_slice($valeurs, 1) as $rang => $valeur) {
            $somme += ($rang + 1) * $valeur;
        }

        return [...$valeurs, $somme % 103, 106];
    }

    /** Les modules d'un Code 128 (1 : barre, 0 : espace). */
    public static function code128(string $texte): string
    {
        $modules = '';
        foreach (self::symboles128($texte) as $valeur) {
            foreach (str_split(self::MOTIFS_128[$valeur]) as $rang => $largeur) {
                $modules .= str_repeat($rang % 2 === 0 ? '1' : '0', (int) $largeur);
            }
        }

        return $modules;
    }

    /** Les barres (début, épaisseur) d'une suite de modules. */
    private static function barres(string $modules): array
    {
        preg_match_all('/1+/', $modules, $suites, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $suite) => [$suite[1], strlen($suite[0])], $suites[0]);
    }
}
