<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Representant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Copier vers » (plan de parité fripv2, socle S4.3) : un devis, une commande ou une facture prépare un document d'un
 * autre type (ou du même), prérempli et sans rien enregistrer ; son enregistrement habituel le crée.
 */
class CopieDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            'fer' => ProduitTenant::create(['numero' => 'FER', 'description' => 'Fer', 'prix_unitaire' => 50])->id,
            'awa' => Representant::create(['code' => 'AWA', 'nom' => 'Awa', 'actif' => true])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    public function test_facture_vers_facture_meme_total(): void
    {
        $source = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10', 'representant_id' => $this->ids['awa'],
            'notes' => 'Livrer au chantier', 'lignes' => [
                ['produit_id' => $this->ids['ciment'], 'quantite' => 3, 'prix_unitaire_ht' => 100, 'remise_ligne' => 10],
                ['produit_id' => $this->ids['fer'], 'quantite' => 2, 'prix_unitaire_ht' => 50],
            ]])->assertCreated();

        // Rien n'est enregistré par la préparation.
        $copie = $this->getJson('/api/factures/'.$source->json('id').'/copie?vers=facture')->assertOk()
            ->assertJsonPath('client_id', $this->ids['client'])->assertJsonPath('representant_id', $this->ids['awa'])
            ->assertJsonPath('notes', 'Livrer au chantier')->assertJsonPath('copie_de.numero', $source->json('numero'))
            ->assertJsonPath('lignes.0.prix_unitaire_ht', 90)->json();
        $this->assertCount(1, $this->getJson('/api/factures')->json('data'));

        // Enregistrée comme une facture saisie : même total hors taxes.
        $nouvelle = $this->postJson('/api/factures', ['client_id' => $copie['client_id'], 'date_facture' => '2026-11-12', 'representant_id' => $copie['representant_id'],
            'lignes' => array_map(fn ($l) => ['produit_id' => $l['produit_id'], 'quantite' => $l['quantite'], 'prix_unitaire_ht' => $l['prix_unitaire_ht']], $copie['lignes'])])
            ->assertCreated();
        $this->assertEquals((float) $source->json('montant_ht'), (float) $nouvelle->json('montant_ht'));
    }

    public function test_commande_vers_devis_et_devis_vers_commande(): void
    {
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-11-10', 'date_livraison_souhaitee' => '2026-11-20',
            'lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 4, 'prix_unitaire_ht' => 100, 'remise_ligne' => 25]]])->assertCreated()->json('id');

        // Vers un devis : le prix net au devis (sans remise de ligne), la livraison souhaitée en date requise.
        $this->getJson("/api/commandes-client/{$commande}/copie?vers=devis")->assertOk()
            ->assertJsonPath('lignes.0.prix_unitaire', 75)->assertJsonMissingPath('lignes.0.prix_unitaire_ht')
            ->assertJsonPath('date_requise', '2026-11-20');

        // D'un devis vers une commande : la date requise en livraison souhaitée.
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10', 'date_requise' => '2026-11-25',
            'lignes' => [['produit_id' => $this->ids['fer'], 'quantite' => 2, 'prix_unitaire' => 50]]])->json('id');
        $this->getJson("/api/devis/{$devis}/copie?vers=commande")->assertOk()
            ->assertJsonPath('date_livraison_souhaitee', '2026-11-25')->assertJsonPath('lignes.0.prix_unitaire_ht', 50);
    }

    public function test_controles_et_isolation(): void
    {
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->ids['fer'], 'quantite' => 2, 'prix_unitaire' => 50]]])->json('id');
        $this->getJson("/api/devis/{$devis}/copie?vers=bon")->assertUnprocessable()->assertJsonValidationErrors(['vers']);
        $this->getJson('/api/devis/999/copie?vers=facture')->assertNotFound();

        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson("/api/devis/{$devis}/copie?vers=facture")->assertNotFound();
    }
}
