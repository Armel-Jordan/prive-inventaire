<?php

namespace Tests\Unit;

use App\Support\MontantEnLettres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Montants en lettres des chèques : règles du français (traditionnelles) et de l'anglais, devises, centimes. */
class MontantEnLettresTest extends TestCase
{
    public static function montants(): array
    {
        return [
            [1, 'XAF', 'fr', 'UN FRANC CFA'],
            [21, 'XAF', 'fr', 'VINGT ET UN FRANCS CFA'],
            [71, 'XAF', 'fr', 'SOIXANTE ET ONZE FRANCS CFA'],
            [77, 'XAF', 'fr', 'SOIXANTE-DIX-SEPT FRANCS CFA'],
            [80, 'XAF', 'fr', 'QUATRE-VINGTS FRANCS CFA'],
            [81, 'XAF', 'fr', 'QUATRE-VINGT-UN FRANCS CFA'],
            [91, 'XAF', 'fr', 'QUATRE-VINGT-ONZE FRANCS CFA'],
            [99, 'XAF', 'fr', 'QUATRE-VINGT-DIX-NEUF FRANCS CFA'],
            [100, 'XAF', 'fr', 'CENT FRANCS CFA'],
            [200, 'XAF', 'fr', 'DEUX CENTS FRANCS CFA'],
            [201, 'XAF', 'fr', 'DEUX CENT UN FRANCS CFA'],
            [1000, 'XAF', 'fr', 'MILLE FRANCS CFA'],
            [21000, 'XAF', 'fr', 'VINGT ET UN MILLE FRANCS CFA'],
            [80000, 'XAF', 'fr', 'QUATRE-VINGT MILLE FRANCS CFA'],
            [200000, 'XAF', 'fr', 'DEUX CENT MILLE FRANCS CFA'],
            [1234567, 'XAF', 'fr', 'UN MILLION DEUX CENT TRENTE-QUATRE MILLE CINQ CENT SOIXANTE-SEPT FRANCS CFA'],
            [2000000, 'XAF', 'fr', 'DEUX MILLIONS DE FRANCS CFA'],
            [200000000, 'XAF', 'fr', 'DEUX CENTS MILLIONS DE FRANCS CFA'],
            [1234.6, 'XAF', 'fr', 'MILLE DEUX CENT TRENTE-CINQ FRANCS CFA'],
            [120.5, 'EUR', 'fr', 'CENT VINGT EUROS ET 50 CENTIMES'],
            [1000000, 'EUR', 'fr', "UN MILLION D'EUROS"],
            [0.01, 'EUR', 'fr', 'ZÉRO EURO ET 1 CENTIME'],
            [342.75, 'USD', 'en', 'THREE HUNDRED FORTY-TWO DOLLARS AND 75 CENTS'],
            [1001, 'CAD', 'en', 'ONE THOUSAND ONE DOLLARS'],
            [2500000, 'XAF', 'en', 'TWO MILLION FIVE HUNDRED THOUSAND CFA FRANCS'],
            [100, 'NGN', 'fr', 'CENT NGN'],
        ];
    }

    #[DataProvider('montants')]
    public function test_montant_en_lettres(float $montant, string $devise, string $langue, string $attendu): void
    {
        $this->assertSame($attendu, MontantEnLettres::ecrire($montant, $devise, $langue));
    }
}
