<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Limite des tentatives de connexion (phase 6.1) : un compte se bloque 15 minutes après 10 mots de passe faux, même si
 * le bon mot de passe arrive ensuite ; une connexion réussie remet le compte à zéro ; les autres comptes ne sont pas
 * touchés ; même règle pour le super-administrateur ; au plus 30 tentatives par minute depuis une même adresse IP.
 */
class LimiteConnexionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-26 09:00:00');
        $this->tenant = Tenant::factory()->create(['slug' => 'alpha', 'modules' => ['ventes']]);
        AdminUser::factory()->for($this->tenant)->create(['email' => 'awa@alpha.test', 'password' => 'secret123']);
        AdminUser::factory()->for($this->tenant)->create(['email' => 'paul@alpha.test', 'password' => 'secret123']);
    }

    private function connexion(string $email, string $motDePasse): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $motDePasse, 'tenant_slug' => 'alpha']);
    }

    public function test_un_compte_se_bloque_apres_dix_echecs_puis_se_debloque(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->connexion('awa@alpha.test', 'mauvais')->assertUnauthorized();
        }

        // Même avec le bon mot de passe, le compte attend la fin du blocage ; un autre compte se connecte.
        $this->connexion('awa@alpha.test', 'secret123')->assertStatus(429)
            ->assertJsonPath('message', 'Trop de tentatives de connexion échouées : réessayez dans 15 minute(s).');
        $this->connexion('AWA@alpha.test', 'secret123')->assertStatus(429);
        $this->connexion('paul@alpha.test', 'secret123')->assertOk();

        $this->travel(16)->minutes();
        $this->connexion('awa@alpha.test', 'secret123')->assertOk();
    }

    public function test_une_connexion_reussie_remet_le_compte_a_zero(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->connexion('awa@alpha.test', 'mauvais')->assertUnauthorized();
        }
        $this->connexion('awa@alpha.test', 'secret123')->assertOk();

        for ($i = 0; $i < 9; $i++) {
            $this->connexion('awa@alpha.test', 'mauvais')->assertUnauthorized();
        }
        $this->connexion('awa@alpha.test', 'secret123')->assertOk();
    }

    public function test_le_super_administrateur_suit_la_meme_regle(): void
    {
        SuperAdmin::factory()->create(['email' => 'super@prise.test', 'password' => 'secret123']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/super-admin/login', ['email' => 'super@prise.test', 'password' => 'mauvais'])->assertUnauthorized();
        }
        $this->postJson('/api/super-admin/login', ['email' => 'super@prise.test', 'password' => 'secret123'])->assertStatus(429);
    }

    public function test_au_plus_trente_tentatives_par_minute_depuis_une_adresse(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->connexion("inconnu{$i}@alpha.test", 'mauvais')->assertUnauthorized();
        }
        $this->connexion('paul@alpha.test', 'secret123')->assertStatus(429);

        $this->travel(2)->minutes();
        $this->connexion('paul@alpha.test', 'secret123')->assertOk();
    }
}
