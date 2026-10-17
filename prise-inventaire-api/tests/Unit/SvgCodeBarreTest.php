<?php

namespace Tests\Unit;

use App\Support\SvgCodeBarre;
use PHPUnit\Framework\TestCase;

/** Dessin des codes-barres : motifs du Code 128, symboles et contrôle, modules des EAN, SVG. */
class SvgCodeBarreTest extends TestCase
{
    public function test_motifs_code_128(): void
    {
        $this->assertCount(107, SvgCodeBarre::MOTIFS_128);
        foreach (SvgCodeBarre::MOTIFS_128 as $valeur => $motif) {
            // Onze modules par symbole (treize pour l'arrêt), barres et espaces de 1 à 4 modules.
            $this->assertSame($valeur === 106 ? 13 : 11, array_sum(str_split($motif)), "symbole {$valeur}");
            $this->assertMatchesRegularExpression('/^[1-4]+$/', $motif);
        }
        $this->assertCount(107, array_unique(SvgCodeBarre::MOTIFS_128));
    }

    public function test_symboles_code_128(): void
    {
        // Jeu B : départ 104, « P » = 48, « J » = 42 ; contrôle (104 + 48 + 2×42) % 103 = 30.
        $this->assertSame([104, 48, 42, 30, 106], SvgCodeBarre::symboles128('PJ'));
        // Jeu C pour un nombre pair de chiffres : départ 105, 12, 34 ; contrôle (105 + 12 + 68) % 103 = 82.
        $this->assertSame([105, 12, 34, 82, 106], SvgCodeBarre::symboles128('1234'));
        // Nombre impair de chiffres : jeu B.
        $this->assertSame(104, SvgCodeBarre::symboles128('123')[0]);
        // Départ, deux caractères et contrôle sur onze modules, l'arrêt sur treize.
        $this->assertSame(11 * 4 + 13, strlen(SvgCodeBarre::code128('PJ')));
    }

    public function test_modules_ean(): void
    {
        $ean = SvgCodeBarre::ean13('4006381333931');
        $this->assertSame(95, strlen($ean));
        $this->assertSame(['101', '01010', '101'], [substr($ean, 0, 3), substr($ean, 45, 5), substr($ean, -3)]);
        // Premier chiffre 4 : jeux LGLLGG ; le chiffre 0 en L (0001101), puis 0 en G (0100111).
        $this->assertSame(['0001101', '0100111'], [substr($ean, 3, 7), substr($ean, 10, 7)]);
        $this->assertSame(67, strlen(SvgCodeBarre::ean8('96385074')));
        $svg = SvgCodeBarre::svg('4006381333931');
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="111" height="50"', $svg);
        $this->assertSame(30, substr_count($svg, '<rect') - 1);
    }
}
