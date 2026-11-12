<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Date requise et poids estimé des documents de vente (plan de parité fripv2, socle S4.1) : la date requise du devis
 * devient la livraison souhaitée de la commande, puis la date requise de la facture et du bon de livraison ; le poids
 * estimé se calcule avec le poids des produits (ceux qui en ont un) et s'imprime.
 */
class DateRequisePoidsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = $this->chez(fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100, 'poids_kg' => 50])->id,
            'fer' => ProduitTenant::create(['numero' => 'FER', 'description' => 'Fer à béton', 'prix_unitaire' => 50, 'poids_kg' => 0.9])->id,
            'service' => ProduitTenant::create(['numero' => 'POSE', 'description' => 'Pose', 'prix_unitaire' => 20])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** 10 sacs de ciment (500 kg), 5 barres de fer (4,5 kg) et une pose (sans poids). */
    private function lignesDevis(): array
    {
        return [
            ['produit_id' => $this->ids['ciment'], 'quantite' => 10, 'prix_unitaire' => 100],
            ['produit_id' => $this->ids['fer'], 'quantite' => 5, 'prix_unitaire' => 50],
            ['produit_id' => $this->ids['service'], 'quantite' => 1, 'prix_unitaire' => 20],
        ];
    }

    public function test_suivie_du_devis_au_bon_de_livraison_avec_le_poids(): void
    {
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'date_requise' => '2026-11-20', 'lignes' => $this->lignesDevis()])->assertCreated()->json('id');
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('poids_estime_kg', 504.5)
            ->assertJsonPath('date_requise', fn ($d) => str_starts_with((string) $d, '2026-11-20'));

        // Devis → commande (livraison souhaitée) → facture → bon de livraison (date requise).
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('poids_estime_kg', 504.5)
            ->assertJsonPath('date_livraison_souhaitee', fn ($d) => str_starts_with((string) $d, '2026-11-20'));
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('poids_estime_kg', 504.5)
            ->assertJsonPath('date_requise', fn ($d) => str_starts_with((string) $d, '2026-11-20'));
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json('id');
        $this->getJson("/api/bons-livraison/{$bon}")->assertJsonPath('poids_estime_kg', 504.5)
            ->assertJsonPath('date_requise', fn ($d) => str_starts_with((string) $d, '2026-11-20'));

        // Impression : date requise et poids.
        $this->chez(function () use ($devis, $commande, $facture, $bon) {
            $pdf = app(DocumentsVentePdf::class);
            $this->assertSame(['20/11/2026', '504,5 kg'], [$pdf->devis(Devis::findOrFail($devis))['infos']['Date requise'], $pdf->devis(Devis::findOrFail($devis))['infos']['Poids estimé']]);
            $this->assertSame('504,5 kg', $pdf->commande(ComClientEntete::findOrFail($commande))['infos']['Poids estimé']);
            $this->assertSame('20/11/2026', $pdf->facture(Facture::findOrFail($facture))['infos']['Date requise']);
            $this->assertSame(['20/11/2026', '504,5 kg'], array_values(array_intersect_key($pdf->bonLivraison(BonLivraison::findOrFail($bon))['infos'], array_flip(['Date requise', 'Poids estimé']))));
        });
    }

    public function test_saisie_modification_et_sans_poids(): void
    {
        // Facture saisie avec sa date requise, puis modifiée ; non renvoyée, elle reste.
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10', 'date_requise' => '2026-11-25',
            'lignes' => [['produit_id' => $this->ids['service'], 'quantite' => 1, 'prix_unitaire_ht' => 20]]])->assertCreated()->json('id');
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('poids_estime_kg', null);
        $this->putJson("/api/factures/{$facture}", ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10', 'date_requise' => '2026-11-30',
            'lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 2, 'prix_unitaire_ht' => 100]]])->assertOk();
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('poids_estime_kg', 100)
            ->assertJsonPath('date_requise', fn ($d) => str_starts_with((string) $d, '2026-11-30'));

        // Devis facturé directement : la facture prend sa date requise.
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'date_requise' => '2026-11-22', 'lignes' => $this->lignesDevis()])->json('id');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $this->postJson("/api/devis/{$devis}/facturer")->assertCreated()
            ->assertJsonPath('facture.date_requise', fn ($d) => str_starts_with((string) $d, '2026-11-22'));

        // Une date invalide est refusée.
        $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'date_requise' => 'demain', 'lignes' => $this->lignesDevis()])->assertUnprocessable()->assertJsonValidationErrors(['date_requise']);
    }
}
