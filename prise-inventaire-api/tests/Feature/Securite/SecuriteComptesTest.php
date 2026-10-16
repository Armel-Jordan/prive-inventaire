<?php

namespace Tests\Feature\Securite;

use App\Mail\LienReinitialisationMotDePasse;
use App\Models\AdminUser;
use App\Models\Tenant;
use App\Services\DeuxFacteurs;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sécurité des comptes (plan de parité fripv2, phase 6.1) : mot de passe oublié (lien unique, sans révéler
 * les comptes, origine contrôlée), changement de mot de passe (sessions fermées), double authentification
 * (activation, connexion en deux étapes, codes de secours, anti-rejeu, réinitialisation par un administrateur).
 */
class SecuriteComptesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $paul;

    protected function setUp(): void
    {
        parent::setUp();
        // La CI utilise un cache fichier qui persiste d'un test à l'autre (limites par IP, défis, échecs).
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['slug' => 'alpha', 'actif' => true]);
        $this->paul = AdminUser::factory()->for($this->tenant)->create([
            'nom' => 'Paul Mbarga', 'email' => 'paul@exemple.cm', 'password' => 'ancien-secret', 'role' => 'user', 'actif' => true,
        ]);
        config(['cors.allowed_origins' => ['https://app.exemple.cm'], 'comptes.url_front' => 'https://defaut.exemple.cm']);
    }

    /** Requête authentifiée par un jeton Sanctum réel (le garde est réinitialisé entre deux appels). */
    private function avecJeton(string $jeton): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$jeton}", 'X-Tenant-Slug' => 'alpha']);
    }

    private function connexion(string $email = 'paul@exemple.cm', string $motDePasse = 'ancien-secret')
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])->postJson('/api/auth/login', ['email' => $email, 'password' => $motDePasse, 'tenant_slug' => 'alpha']);
    }

    private function lienEnvoye(): string
    {
        $lien = null;
        Mail::assertSent(LienReinitialisationMotDePasse::class, function ($courriel) use (&$lien) {
            $lien = $courriel->lien;

            return true;
        });

        return $lien;
    }

    public function test_mot_de_passe_oublie_sans_reveler_les_comptes(): void
    {
        Mail::fake();
        $message = 'Si un compte correspond à ces informations, un courriel avec un lien de réinitialisation vient d\'être envoyé.';

        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'alpha', 'email' => 'paul@exemple.cm', 'origine' => 'https://app.exemple.cm', 'langue' => 'fr'])
            ->assertOk()->assertJson(['message' => $message]);
        Mail::assertSent(LienReinitialisationMotDePasse::class, fn ($c) => $c->hasTo('paul@exemple.cm') && $c->langue === 'fr'
            && preg_match('#^https://app\.exemple\.cm/reinitialiser-mot-de-passe\?jeton=[A-Za-z0-9]{64}$#', $c->lien) === 1);

        // Le jeton n'est stocké que haché.
        $jeton = substr($this->lienEnvoye(), -64);
        $this->assertDatabaseHas('reinitialisations_mot_de_passe', ['admin_user_id' => $this->paul->id, 'jeton' => hash('sha256', $jeton)]);
        $this->assertDatabaseMissing('reinitialisations_mot_de_passe', ['jeton' => $jeton]);

        // Compte inconnu, entreprise inconnue : même réponse, aucun courriel.
        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'alpha', 'email' => 'inconnu@exemple.cm'])->assertOk()->assertJson(['message' => $message]);
        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'beta', 'email' => 'paul@exemple.cm'])->assertOk()->assertJson(['message' => $message]);
        Mail::assertSentCount(1);

        // Une origine non autorisée n'est jamais reprise dans le lien.
        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'alpha', 'email' => 'paul@exemple.cm', 'origine' => 'https://pirate.example'])->assertOk();
        Mail::assertSent(LienReinitialisationMotDePasse::class, fn ($c) => str_starts_with($c->lien, 'https://defaut.exemple.cm/reinitialiser-mot-de-passe?jeton='));
        // La nouvelle demande annule la précédente.
        $this->assertSame(1, DB::table('reinitialisations_mot_de_passe')->where('admin_user_id', $this->paul->id)->count());
    }

    public function test_reinitialisation_par_le_lien(): void
    {
        Mail::fake();
        $ancienneSession = $this->paul->createToken('autre-appareil')->plainTextToken;
        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'alpha', 'email' => 'paul@exemple.cm']);
        $jeton = substr($this->lienEnvoye(), -64);

        $this->postJson('/api/auth/reinitialiser-mot-de-passe', ['jeton' => $jeton, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'Le mot de passe doit contenir au moins 8 caractères.']);
        $this->postJson('/api/auth/reinitialiser-mot-de-passe', ['jeton' => $jeton, 'password' => 'nouveau-secret', 'password_confirmation' => 'autre-chose'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'La confirmation ne correspond pas au mot de passe.']);

        $this->postJson('/api/auth/reinitialiser-mot-de-passe', ['jeton' => $jeton, 'password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'])->assertOk();

        // Sessions ouvertes fermées, ancien mot de passe refusé, nouveau accepté, lien à usage unique.
        $this->avecJeton($ancienneSession)->getJson('/api/auth/me')->assertUnauthorized();
        $this->connexion()->assertUnauthorized();
        $this->connexion(motDePasse: 'nouveau-secret')->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/auth/reinitialiser-mot-de-passe', ['jeton' => $jeton, 'password' => 'encore-autre', 'password_confirmation' => 'encore-autre'])
            ->assertUnprocessable()->assertJson(['message' => 'Ce lien n\'est plus valable : demandez-en un nouveau.']);

        // Un lien expire au bout d'une heure.
        $this->postJson('/api/auth/mot-de-passe-oublie', ['tenant_slug' => 'alpha', 'email' => 'paul@exemple.cm']);
        $jeton = substr($this->lienEnvoye(), -64);
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/reinitialiser-mot-de-passe', ['jeton' => $jeton, 'password' => 'encore-autre', 'password_confirmation' => 'encore-autre'])->assertUnprocessable();
    }

    public function test_changement_du_mot_de_passe_ferme_les_autres_sessions(): void
    {
        $courante = $this->connexion()->assertOk()->json('token');
        $autre = $this->paul->createToken('autre-appareil')->plainTextToken;

        $this->avecJeton($courante)->putJson('/api/auth/mot-de-passe', ['mot_de_passe_actuel' => 'faux', 'password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors(['mot_de_passe_actuel' => 'Mot de passe actuel incorrect.']);
        $this->avecJeton($courante)->putJson('/api/auth/mot-de-passe', ['mot_de_passe_actuel' => 'ancien-secret', 'password' => 'ancien-secret', 'password_confirmation' => 'ancien-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'Le nouveau mot de passe doit être différent de l\'actuel.']);

        $this->avecJeton($courante)->putJson('/api/auth/mot-de-passe', ['mot_de_passe_actuel' => 'ancien-secret', 'password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'])->assertOk();

        $this->avecJeton($courante)->getJson('/api/auth/me')->assertOk();
        $this->avecJeton($autre)->getJson('/api/auth/me')->assertUnauthorized();
        $this->connexion(motDePasse: 'nouveau-secret')->assertOk();
    }

    public function test_activation_puis_connexion_en_deux_etapes(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $jeton = $this->connexion()->json('token');
        $this->avecJeton($jeton)->getJson('/api/auth/deux-facteurs')->assertOk()->assertJson(['active' => false]);

        $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/preparer', ['password' => 'faux'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $preparation = $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/preparer', ['password' => 'ancien-secret'])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $preparation['uri']);
        $secret = $preparation['secret'];
        // Le secret est chiffré en base et jamais renvoyé avec l'utilisateur.
        $this->assertNotSame($secret, DB::table('admin_users')->where('id', $this->paul->id)->value('deux_facteurs_secret'));
        $this->assertArrayNotHasKey('deux_facteurs_secret', $this->paul->fresh()->toArray());

        $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/activer', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $activation = $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/activer', ['code' => Totp::code($secret, Totp::pas())])->assertOk()->json();
        $this->assertTrue($activation['active']);
        $this->assertCount(8, $activation['codes_secours']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $activation['codes_secours'][0]);

        // Le mot de passe seul ne suffit plus : un défi est remis, sans jeton.
        $reponse = $this->connexion()->assertForbidden()->assertJson(['success' => false, 'deux_facteurs' => true])->assertJsonMissingPath('token');
        $defi = $reponse->json('defi');

        // Le code déjà utilisé pour l'activation est refusé (anti-rejeu) ; le suivant est accepté.
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => Totp::code($secret, Totp::pas())])->assertUnprocessable();
        $this->travel(30)->seconds();
        $session = $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => Totp::code($secret, Totp::pas())])
            ->assertOk()->assertJsonPath('user.deux_facteurs', true)->json('token');
        $this->avecJeton($session)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.deux_facteurs', true);

        // Le défi ne resert pas.
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => Totp::code($secret, Totp::pas() + 1)])
            ->assertUnprocessable()->assertJson(['defi_expire' => true, 'message' => 'La connexion a expiré : saisissez de nouveau votre mot de passe.']);

        // Code de secours : accepté une seule fois.
        $code = $activation['codes_secours'][0];
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $this->connexion()->json('defi'), 'code' => strtolower($code)])->assertOk();
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $this->connexion()->json('defi'), 'code' => $code])->assertUnprocessable();
        $this->avecJeton($jeton)->getJson('/api/auth/deux-facteurs')->assertJson(['active' => true, 'codes_secours_restants' => 7]);
    }

    public function test_essais_limites_codes_regeneres_et_desactivation(): void
    {
        $jeton = $this->connexion()->json('token');
        $codes = $this->activer($this->paul);

        // Au-delà de 5 codes faux, le défi est annulé, même avec ensuite un bon code.
        $defi = $this->connexion()->json('defi');
        foreach (range(1, 5) as $essai) {
            $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => $codes[0]])->assertUnprocessable();

        // Nouveaux codes de secours : les anciens ne valent plus.
        $nouveaux = $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/codes-secours', ['password' => 'ancien-secret'])->assertOk()->json('codes_secours');
        $this->assertCount(8, $nouveaux);
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $this->connexion()->json('defi'), 'code' => $codes[1]])->assertUnprocessable();

        // Désactivation (mot de passe exigé) : retour à la connexion par mot de passe seul.
        $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/desactiver', ['password' => 'faux'])->assertUnprocessable();
        $this->avecJeton($jeton)->postJson('/api/auth/deux-facteurs/desactiver', ['password' => 'ancien-secret'])->assertOk()->assertJson(['active' => false]);
        $this->connexion()->assertOk()->assertJsonStructure(['token']);
    }

    public function test_trop_de_codes_faux_bloquent_un_moment_l_etape_du_code(): void
    {
        $codes = $this->activer($this->paul);

        // 10 codes faux répartis sur deux défis, à une minute d'écart (hors limite par adresse IP).
        foreach (range(1, 2) as $serie) {
            $defi = $this->connexion()->json('defi');
            foreach (range(1, 5) as $essai) {
                $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $defi, 'code' => '000000'])->assertUnprocessable();
            }
            $this->travel(61)->seconds();
        }

        // Même un bon code est refusé pendant le blocage, puis accepté après.
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $this->connexion()->json('defi'), 'code' => $codes[0]])
            ->assertStatus(429)->assertJson(['message' => 'Trop de codes invalides : réessayez dans 15 minutes.']);
        $this->travel(15)->minutes();
        $this->postJson('/api/auth/login/deux-facteurs', ['defi' => $this->connexion()->json('defi'), 'code' => $codes[0]])->assertOk();
    }

    public function test_un_administrateur_desactive_la_double_authentification_d_un_utilisateur(): void
    {
        $this->activer($this->paul);
        $admin = AdminUser::factory()->for($this->tenant)->create(['email' => 'admin@exemple.cm', 'password' => 'admin-secret', 'role' => 'admin', 'actif' => true]);
        $autreAdmin = AdminUser::factory()->for(Tenant::factory()->create(['slug' => 'beta']))->create(['role' => 'admin']);

        // Un utilisateur sans rôle administrateur n'y a pas accès.
        $this->avecJeton($this->paul->createToken('t')->plainTextToken)->getJson('/api/securite/utilisateurs')->assertForbidden();

        $jetonAdmin = $this->connexion('admin@exemple.cm', 'admin-secret')->json('token');
        $liste = collect($this->avecJeton($jetonAdmin)->getJson('/api/securite/utilisateurs')->assertOk()->json())->keyBy('email');
        $this->assertTrue($liste['paul@exemple.cm']['deux_facteurs']);
        $this->assertFalse($liste['admin@exemple.cm']['deux_facteurs']);
        $this->assertArrayNotHasKey('deux_facteurs_secret', $liste['paul@exemple.cm']);

        // Un administrateur d'une autre entreprise ne le peut pas.
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$autreAdmin->createToken('t')->plainTextToken, 'X-Tenant-Slug' => 'beta'])
            ->deleteJson("/api/securite/utilisateurs/{$this->paul->id}/deux-facteurs")->assertNotFound();

        $this->avecJeton($jetonAdmin)->deleteJson("/api/securite/utilisateurs/{$this->paul->id}/deux-facteurs")->assertOk();
        $this->connexion()->assertOk()->assertJsonStructure(['token']);
    }

    /** @return list<string> codes de secours */
    private function activer(AdminUser $utilisateur): array
    {
        $service = app(DeuxFacteurs::class);
        $secret = $service->preparer($utilisateur)['secret'];

        return $service->activer($utilisateur, Totp::code($secret, Totp::pas()));
    }
}
