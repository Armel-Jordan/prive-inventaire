<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** TOTP conforme aux vecteurs de test de la RFC 6238 (annexe B, SHA-1, 6 derniers chiffres). */
class TotpTest extends TestCase
{
    // « 12345678901234567890 » en base32.
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_vecteurs_de_la_rfc_6238(): void
    {
        $attendus = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];

        foreach ($attendus as $horodatage => $code) {
            $this->assertSame($code, Totp::code(self::SECRET_RFC, Totp::pas($horodatage)), "T = {$horodatage}");
        }
    }

    public function test_verification_avec_fenetre_et_sans_rejeu(): void
    {
        $maintenant = 1234567890;
        $pas = Totp::pas($maintenant);

        $this->assertSame($pas, Totp::verifier(self::SECRET_RFC, '005924', null, 1, $maintenant));
        // Code du pas précédent accepté (horloge du téléphone en retard), pas celui d'il y a deux pas.
        $this->assertSame($pas - 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 1), null, 1, $maintenant));
        $this->assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 2), null, 1, $maintenant));
        // Un code déjà utilisé (pas ≤ dernier pas accepté) est refusé.
        $this->assertNull(Totp::verifier(self::SECRET_RFC, '005924', $pas, 1, $maintenant));
        // Espaces tolérés, format invalide refusé.
        $this->assertSame($pas, Totp::verifier(self::SECRET_RFC, '005 924', null, 1, $maintenant));
        $this->assertNull(Totp::verifier(self::SECRET_RFC, 'abcdef', null, 1, $maintenant));
    }

    public function test_secret_et_adresse_otpauth(): void
    {
        $secret = Totp::genererSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::genererSecret());

        $this->assertSame(
            'otpauth://totp/Prise%20Inventaire:paul%40exemple.cm?secret=ABC&issuer=Prise%20Inventaire&algorithm=SHA1&digits=6&period=30',
            Totp::uri('ABC', 'paul@exemple.cm', 'Prise Inventaire'),
        );
    }
}
