<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Corrections de la campagne de tests « inventaire et paramètres » du 2026-10-16 : réponse JSON d'un appel
 * non authentifié, jeton révoqué à la déconnexion, code de secteur modifiable, jours depuis le dernier scan,
 * auteur d'un transfert planifié, profil consulté sans fiche créée.
 */
class CampagneInventaireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $utilisateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->utilisateur = AdminUser::factory()->for($this->tenant)->create(['nom' => 'Awa Ndiaye']);
    }

    private function connecter(): void
    {
        Sanctum::actingAs($this->utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    public function test_un_appel_non_authentifie_recoit_un_401_json_meme_sans_entete_accept(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_la_deconnexion_revoque_le_jeton(): void
    {
        $jeton = $this->utilisateur->createToken('web')->plainTextToken;
        $entetes = ['Authorization' => "Bearer {$jeton}", 'Accept' => 'application/json'];

        $this->withHeaders($entetes)->getJson('/api/auth/me')->assertOk();
        $this->withHeaders($entetes)->postJson('/api/auth/logout')->assertOk();
        // Le garde garde l'utilisateur en mémoire d'une requête à l'autre dans un même test.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($entetes)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_un_secteur_au_code_libre_se_modifie(): void
    {
        $this->connecter();
        foreach (['ST1', 'SEC-001'] as $code) {
            $id = $this->postJson('/api/secteurs', ['code' => $code, 'nom' => "Secteur {$code}"])->assertCreated()->json('secteur.id');

            $this->putJson("/api/secteurs/{$id}", ['code' => $code, 'nom' => "Secteur {$code} renommé"])
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_les_jours_depuis_le_dernier_scan_sont_positifs_et_absents_sans_scan(): void
    {
        $this->connecter();
        $this->chezLeTenant(function () {
            Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true]);
            Secteur::create(['code' => 'B1', 'nom' => 'Allée 2', 'actif' => true]);
            ScanTenant::create([
                'numero' => 'P-1', 'type' => 'produit', 'quantite' => 3, 'unite_mesure' => 'UN',
                'employe' => 'E1', 'secteur' => 'A1', 'date_saisie' => '2026-10-13 08:00:00',
            ]);
        });

        $suggestions = collect($this->getJson('/api/inventaire-tournant/suggestions')->assertOk()->json())
            ->flatten(1)->filter(fn ($s) => is_array($s) && isset($s['secteur']))->keyBy('secteur');

        $this->assertSame(3, $suggestions['A1']['jours_depuis_scan']);
        $this->assertNull($suggestions['B1']['jours_depuis_scan']);
        $this->assertGreaterThan($suggestions['A1']['score_priorite'], $suggestions['B1']['score_priorite']);
    }

    public function test_l_evolution_annuelle_compte_les_scans_par_mois(): void
    {
        $this->connecter();
        $this->chezLeTenant(function () {
            foreach (['2026-03-05 09:00:00', '2026-10-01 09:00:00', '2026-10-15 17:30:00', '2025-10-15 09:00:00'] as $date) {
                ScanTenant::create([
                    'numero' => 'P-1', 'type' => 'produit', 'quantite' => 2, 'unite_mesure' => 'UN',
                    'employe' => 'E1', 'secteur' => 'A1', 'date_saisie' => $date,
                ]);
            }
        });

        $rapport = $this->getJson('/api/rapports/evolution-annuelle?annee=2026')->assertOk()->json('rapport');

        $this->assertSame([1, 2, 4], [$rapport[2]['scans'], $rapport[9]['scans'], (int) $rapport[9]['quantite_scans']]);
        $this->assertSame(3, array_sum(array_column($rapport, 'scans')));
    }

    public function test_un_transfert_planifie_porte_le_nom_de_son_auteur(): void
    {
        $this->connecter();

        $this->postJson('/api/transferts-planifies', [
            'type' => 'transfert', 'produit_numero' => 'P-1', 'secteur_source' => 'A1', 'secteur_destination' => 'B1',
            'quantite' => 5, 'employe' => 'E1', 'date_planifiee' => '2026-10-17 09:00:00',
        ])->assertCreated()->assertJsonPath('transfert.cree_par', 'Awa Ndiaye');
    }

    public function test_consulter_son_profil_ne_cree_pas_de_fiche_employe(): void
    {
        $this->connecter();

        $this->getJson('/api/profile')->assertOk()
            ->assertJsonPath('employe.email', $this->utilisateur->email)
            ->assertJsonPath('employe.nom', 'Ndiaye')
            ->assertJsonPath('employe.prenom', 'Awa');
        $this->assertSame(0, $this->chezLeTenant(fn () => EmployeTenant::count()));

        $this->putJson('/api/profile', ['telephone' => '+237 600 00 00 00'])->assertOk();
        $this->assertSame(1, $this->chezLeTenant(fn () => EmployeTenant::where('email', $this->utilisateur->email)->count()));
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('employe.telephone', '+237 600 00 00 00');
    }
}
