<?php

namespace Tests\Feature\Produits;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\PortailParametres;
use App\Models\ProduitTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Services\Portail\CommandesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nature et options du produit (plan de parité fripv2, socle S2) : par défaut, rien ne change ; un service n'est ni
 * stocké, ni suivi, ni gardé en reliquat ; un produit non vendu ou masqué quitte l'espace client ; un produit non
 * commissionnable sort de la commission ; « Garde ses reliquats » décoché refuse les reliquats.
 */
class NatureProduitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $secteurId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        [$this->secteurId, $this->clientId] = $this->chez(fn () => [
            Secteur::create(['code' => 'S1', 'nom' => 'Magasin'])->id,
            Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function produit(string $numero, array $autres = []): int
    {
        return $this->postJson('/api/produits', $autres + ['numero' => $numero, 'description' => "Produit {$numero}", 'secteur_id' => $this->secteurId, 'prix_unitaire' => 100])
            ->assertCreated()->json('produit.id');
    }

    public function test_valeurs_par_defaut_et_regles_du_service(): void
    {
        // Sans case transmise : stocké, acheté, vendu… comme avant.
        $ciment = $this->produit('CIM');
        $this->assertSame(array_values(ProduitTenant::NATURE),
            array_values($this->chez(fn () => ProduitTenant::findOrFail($ciment)->only(array_keys(ProduitTenant::NATURE)))));

        // Un service n'est ni stocké ni gardé en reliquat ; il ne peut pas être suivi par lot.
        $pose = $this->produit('POSE', ['service' => true, 'stocke' => true, 'conserve_reliquat' => true]);
        $this->assertSame([true, false, false], array_values($this->chez(fn () => ProduitTenant::findOrFail($pose)->only(['service', 'stocke', 'conserve_reliquat']))));
        $this->putJson("/api/produits/{$pose}", ['suivi' => 'lot'])->assertUnprocessable()->assertJsonValidationErrors(['service']);
        $this->putJson("/api/produits/{$ciment}", ['suivi' => 'lot'])->assertOk();
        $this->putJson("/api/produits/{$ciment}", ['service' => true])->assertUnprocessable()->assertJsonValidationErrors(['service']);

        // Les cases se changent une à une ; non transmises, elles restent.
        $this->putJson("/api/produits/{$ciment}", ['vendu' => false, 'commissionnable' => false])->assertOk();
        $this->putJson("/api/produits/{$ciment}", ['description' => 'Ciment 50 kg'])->assertOk();
        $this->assertSame([false, false, true], array_values($this->chez(fn () => ProduitTenant::findOrFail($ciment)->only(['vendu', 'commissionnable', 'achete']))));
    }

    public function test_espace_client_commission_et_reliquats(): void
    {
        $ciment = $this->produit('CIM');
        $cache = $this->produit('CACHE', ['visible_portail' => false]);
        $interne = $this->produit('INT', ['vendu' => false]);
        $pose = $this->produit('POSE', ['service' => true, 'commissionnable' => false]);

        // Espace client : seulement les produits vendus et visibles.
        $proposes = $this->chez(function () {
            PortailParametres::create(['actif' => true]);

            return app(CommandesPortail::class)->produitsProposes()->pluck('id')->all();
        });
        $this->assertContains($ciment, $proposes);
        $this->assertNotContains($cache, $proposes);
        $this->assertNotContains($interne, $proposes);

        // Commission : la ligne du service non commissionnable n'en donne pas (10 % de 100, et non de 200).
        $awa = $this->postJson('/api/referentiels/representants', ['nom' => 'Awa', 'taux_commission' => 10, 'actif' => true])->assertCreated()->json('id');
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-10', 'representant_id' => $awa, 'lignes' => [
            ['produit_id' => $ciment, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
            ['produit_id' => $pose, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
        ]])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        $ligne = $this->getJson('/api/rapports/bibliotheque/ventes-par-representant?du=2026-10-01&au=2026-10-31')->assertOk()->json('lignes.0');
        $this->assertEquals([200, 10], [$ligne['ventes_ht'], $ligne['commission']]);

        // Reliquats : refusés pour un produit qui n'en garde pas (le service).
        $this->postJson('/api/reliquats-clients', ['client_id' => $this->clientId, 'produit_id' => $pose, 'quantite' => 1, 'prix_unitaire_ht' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors(['produit_id']);
        $this->postJson('/api/reliquats-clients', ['client_id' => $this->clientId, 'produit_id' => $ciment, 'quantite' => 1, 'prix_unitaire_ht' => 100])->assertCreated();
    }
}
