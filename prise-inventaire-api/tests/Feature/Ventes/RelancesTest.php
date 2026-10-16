<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\FactureRelance;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use App\Taches\RelancerFacturesEnRetard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relances de paiement (phase 1.5) : factures en retard, relance par courriel notée à l'envoi, relance notée à la
 * main, historique, relances automatiques (tâche planifiée : délai, intervalle, maximum, client sans courriel), droits.
 */
class RelancesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        Mail::fake();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'compta@client.test'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir('admin');
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(string $role): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => $role]), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Facture émise de 120 TTC. */
    private function facture(string $date, ?string $echeance, ?int $client = null): int
    {
        $id = $this->postJson('/api/factures', array_filter([
            'client_id' => $client ?? $this->clientId, 'date_facture' => $date, 'date_echeance' => $echeance,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ]))->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    public function test_factures_en_retard_relance_par_courriel_et_relance_notee(): void
    {
        $enRetard = $this->facture('2026-08-01', '2026-09-01');
        $this->facture('2026-10-10', '2026-10-20');
        $sansEcheance = $this->facture('2026-10-01', null);

        $liste = $this->getJson('/api/relances')->assertOk()->json();
        $this->assertSame([[$enRetard, 45], [$sansEcheance, 15]], array_map(fn ($f) => [$f['facture_id'], $f['retard']], $liste['factures']));
        $this->assertEquals([2, 1, 240, 2], array_values($liste['totaux']));
        $this->assertSame([$enRetard], array_column($this->getJson('/api/relances?retard_min=30')->json('factures'), 'facture_id'));

        // Courriel de relance : modèle « relance », facture jointe ; noté sur la facture à son envoi.
        $courriel = $this->getJson("/api/courriels/preparer?type=relance&document_id={$enRetard}")->assertOk()->json();
        $this->assertStringStartsWith('Relance : facture FAC-00001', $courriel['objet']);
        $this->assertStringContainsString('échue le 01/09/2026', $courriel['contenu']);
        $this->assertSame(['compta@client.test'], $courriel['destinataires']);
        $this->assertSame('facture-FAC-00001.pdf', $courriel['piece_jointe']);
        $envoi = $this->postJson('/api/courriels/envois', [
            'type' => 'relance', 'document_id' => $enRetard, 'langue' => 'fr', 'destinataires' => $courriel['destinataires'],
            'objet' => $courriel['objet'], 'contenu' => $courriel['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye')->json('id');

        $this->postJson("/api/factures/{$enRetard}/relances", ['mode' => 'telephone', 'date' => '2026-10-16', 'notes' => 'Promet de payer vendredi'])
            ->assertCreated()->assertJsonPath('niveau', 2);
        $relances = $this->getJson("/api/factures/{$enRetard}/relances")->assertOk()->json();
        $this->assertSame([[1, 'courriel', $envoi, false], [2, 'telephone', null, false]],
            array_map(fn ($r) => [$r['niveau'], $r['mode'], $r['envoi_id'], $r['automatique']], $relances));

        $ligne = collect($this->getJson('/api/relances')->json('factures'))->firstWhere('facture_id', $enRetard);
        $this->assertSame([2, 'telephone'], [$ligne['nb_relances'], $ligne['derniere_relance']['mode']]);
        $this->assertSame([$sansEcheance], array_column($this->getJson('/api/relances?jamais=1')->json('factures'), 'facture_id'));
        $this->assertSame(2, $this->getJson("/api/relances/historique?client_id={$this->clientId}")->assertOk()->json('total'));

        $this->postJson("/api/factures/{$enRetard}/relances", ['mode' => 'pigeon', 'date' => '2026-10-16'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->postJson("/api/factures/{$enRetard}/relances", ['mode' => 'courrier', 'date' => '2026-10-17'])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_relances_automatiques_delai_intervalle_et_maximum(): void
    {
        $a = $this->facture('2026-08-01', '2026-09-01');
        $c = $this->facture('2026-09-01', '2026-10-01');
        $this->facture('2026-09-15', '2026-10-06');
        $sansCourriel = $this->chezLeTenant(fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Sans courriel', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $this->facture('2026-08-01', '2026-09-01', $sansCourriel);
        $relancer = fn () => $this->chezLeTenant(fn () => app(RelancerFacturesEnRetard::class)->executer());

        // Délai de 15 jours : A (45 j) et C (15 j) ; pas la facture à 10 j, ni celle du client sans courriel.
        $this->assertSame('2 relance(s) envoyée(s).', $relancer());
        $this->assertEqualsCanonicalizing([$a, $c], $this->chezLeTenant(fn () => FactureRelance::where('automatique', true)->pluck('facture_id')->all()));
        $this->assertSame(2, $this->chezLeTenant(fn () => CourrielEnvoi::where('type', 'relance')->where('statut', 'envoye')->count()));
        // Une seule relance par courriel, même notée à l'envoi.
        $this->assertSame(2, $this->chezLeTenant(fn () => FactureRelance::count()));

        // Intervalle : rien le jour même, puis de nouveau 15 jours plus tard ; 3 relances au plus.
        $this->assertSame('Aucune facture à relancer.', $relancer());
        $this->travel(15)->days();
        $this->assertSame('3 relance(s) envoyée(s).', $relancer());
        $this->travel(15)->days();
        $relancer();
        $this->travel(15)->days();
        $relancer();
        $this->assertSame(3, $this->chezLeTenant(fn () => FactureRelance::where('facture_id', $a)->count()));
        $this->assertSame([1, 2, 3], $this->chezLeTenant(fn () => FactureRelance::where('facture_id', $a)->orderBy('id')->pluck('niveau')->all()));
    }

    public function test_droits_des_relances(): void
    {
        $facture = $this->facture('2026-08-01', '2026-09-01');

        // Rôles système : consultation pour tous, relance avec le droit de modifier les factures.
        $this->agir('user');
        $this->getJson('/api/relances')->assertOk();
        $this->postJson("/api/factures/{$facture}/relances", ['mode' => 'telephone', 'date' => '2026-10-16'])->assertForbidden();
        $this->agir('manager');
        $this->postJson("/api/factures/{$facture}/relances", ['mode' => 'telephone', 'date' => '2026-10-16'])->assertCreated();

        $this->agir('admin');
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->json('onglets'))->firstWhere('cle', 'ventes')['modules']);
        $this->assertSame(['relances.voir', 'relances.creer'], array_column($modules->firstWhere('cle', 'relances')['permissions'], 'code'));

        // Un groupe sans le droit de consulter les relances : la liste est refusée.
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Ventes sans relances', 'permissions' => ['factures' => ['can_view' => true]]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'role_custom_id' => $groupe]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->getJson('/api/relances')->assertForbidden();
    }
}
