<?php

namespace Tests\Unit;

use App\Support\CodesBarres;
use Tests\TestCase;

/** Types de codes-barres : détection et clés de contrôle des EAN-13, EAN-8 et UPC-A (messages traduits : l'application est démarrée). */
class CodesBarresTest extends TestCase
{
    public function test_detection_et_cles(): void
    {
        $this->assertSame(['ean13', 'ean13', 'ean8', 'upca', 'code128', 'code128'], array_map([CodesBarres::class, 'detecter'], [
            '4006381333931', '5901234123457', '96385074', '036000291452', '4006381333932', 'CARTON-CIM-50',
        ]));
        $this->assertSame([1, 7, 4, 2], [CodesBarres::cle('400638133393'), CodesBarres::cle('590123412345'), CodesBarres::cle('9638507'), CodesBarres::cle('03600029145')]);
        $this->assertNull(CodesBarres::erreur('4006381333931', 'ean13'));
        $this->assertSame('La clé de contrôle du code EAN-13 est fausse : le dernier chiffre devrait être 1.', CodesBarres::erreur('4006381333932', 'ean13'));
        $this->assertSame('Un code EAN-8 compte 8 chiffres.', CodesBarres::erreur('9638507', 'ean8'));
        $this->assertSame('Un code 128 compte de 1 à 48 caractères imprimables, sans accent.', CodesBarres::erreur('CIMENT-ÉTÉ', 'code128'));
        $this->assertNull(CodesBarres::erreur('CIMENT-ÉTÉ', 'interne'));
    }
}
