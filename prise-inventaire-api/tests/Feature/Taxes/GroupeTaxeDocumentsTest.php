<?php

namespace Tests\Feature\Taxes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\DocumentTaxe;
use App\Models\GroupeTaxe;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Groupe de taxes du client et des documents (plan de parité fripv2, socle S4.1 ; groupes par défaut et exemptions) :
 * celui de la fiche client est proposé sur ses devis, commandes et factures et passe de l'un à l'autre ; il s'applique
 * aux lignes sans groupe ni taux (un groupe vide exonère) ; en changer refait les taxes des lignes.
 */
class GroupeTaxeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        // TVA 17,5 % + CAC 10 % de la TVA (19,25 %), groupe par défaut ; et un groupe vide, « exonéré ».
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->json('id');
        $this->ids['tva'] = $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'par_defaut' => true, 'actif' => true, 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated()->json('id');
        $this->ids['exo'] = $this->postJson('/api/groupes-taxes', ['code' => 'EXO', 'nom' => 'Exonéré', 'par_defaut' => false, 'actif' => true, 'lignes' => []])
            ->assertCreated()->json('id');

        $this->ids += $this->chez(fn () => [
            'ong' => Client::create(['code' => 'ONG', 'raison_sociale' => 'ONG exonérée', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0',
                'groupe_taxe_id' => $this->ids['exo']])->id,
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
        ]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** 10 × 100 = 1 000 HT. */
    private function lignes(array $autres = []): array
    {
        return [$autres + ['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100]];
    }

    private function commande(string $client, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/commandes-client', $autres + ['client_id' => $this->ids[$client], 'date_commande' => '2026-11-10', 'lignes' => $this->lignes()]);
    }

    public function test_groupe_du_client_puis_du_document(): void
    {
        // Le client exonéré : son groupe vide, aucune taxe ; les autres : le groupe par défaut.
        $exoneree = $this->commande('ong')->assertCreated()->assertJsonPath('groupe_taxe_id', $this->ids['exo']);
        $this->assertEquals(1000, (float) $exoneree->json('montant_ttc'));
        $taxee = $this->commande('client')->assertCreated()->assertJsonPath('groupe_taxe_id', null);
        $this->assertEquals(1192.5, (float) $taxee->json('montant_ttc'));

        // Choisi sur le document, il l'emporte sur celui du client ; un taux saisi sur la ligne garde le calcul historique.
        $this->assertEquals(1192.5, (float) $this->commande('ong', ['groupe_taxe_id' => $this->ids['tva']])->assertCreated()->json('montant_ttc'));
        $this->assertEquals(1100, (float) $this->commande('ong', ['lignes' => $this->lignes(['taux_tva' => 10])])->assertCreated()->json('montant_ttc'));

        // Facture saisie et facture d'une commande : le groupe suit, les taxes aussi.
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['ong'], 'date_facture' => '2026-11-10', 'lignes' => $this->lignes()])
            ->assertCreated()->assertJsonPath('groupe_taxe_id', $this->ids['exo']);
        $this->assertEquals(0, (float) $facture->json('montant_tva'));
        $commande = $exoneree->json('id');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $deCommande = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->assertJsonPath('groupe_taxe_id', $this->ids['exo']);
        $this->assertEquals(1000, (float) $deCommande->json('montant_ttc'));
        $this->getJson('/api/factures/'.$deCommande->json('id'))->assertJsonPath('groupe_taxe.nom', 'Exonéré');

        // Devis : le groupe du client, repris par la commande issue de la conversion.
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['ong'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('groupe_taxe_id', $this->ids['exo'])->json('id');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $converti = $this->postJson("/api/devis/{$devis}/convertir")->assertOk();
        $this->assertSame($this->ids['exo'], $converti->json('commande.groupe_taxe_id'));
        $this->assertEquals(1000, (float) $converti->json('commande.montant_ttc'));
    }

    public function test_changer_le_groupe_refait_les_taxes(): void
    {
        $commande = $this->commande('client')->json('id');
        $this->assertSame(2, DocumentTaxe::count());

        // Sans renvoyer les lignes : leurs taxes et les totaux sont refaits.
        $this->putJson("/api/commandes-client/{$commande}", ['groupe_taxe_id' => $this->ids['exo']])->assertOk();
        $refaite = $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('groupe_taxe.nom', 'Exonéré');
        $this->assertEquals(1000, (float) $refaite->json('montant_ttc'));
        $this->assertEquals(0, (float) $refaite->json('lignes.0.taux_tva'));
        $this->assertSame(0, DocumentTaxe::count());

        // Avec les lignes : calculées avec le groupe du document.
        $this->putJson("/api/commandes-client/{$commande}", ['groupe_taxe_id' => $this->ids['tva'], 'lignes' => $this->lignes()])->assertOk();
        $this->assertEquals(1192.5, (float) $this->chez(fn () => ComClientEntete::findOrFail($commande)->montant_ttc));

        // Facture brouillon modifiée : le groupe envoyé s'applique.
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10', 'lignes' => $this->lignes()])->json('id');
        $this->putJson("/api/factures/{$facture}", ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10', 'groupe_taxe_id' => $this->ids['exo'],
            'lignes' => $this->lignes()])->assertOk();
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('groupe_taxe_id', $this->ids['exo'])->assertJsonPath('montant_tva', fn ($v) => (float) $v === 0.0);
    }

    public function test_fiche_client_et_controles(): void
    {
        // La fiche client prend un groupe ; celui d'une autre entreprise ou désactivé est refusé.
        $this->putJson("/api/clients/{$this->ids['client']}", ['groupe_taxe_id' => $this->ids['exo']])->assertOk()->assertJsonPath('groupe_taxe_id', $this->ids['exo']);
        $ailleurs = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id, fn () => GroupeTaxe::create(['code' => 'X', 'nom' => 'Ailleurs', 'actif' => true])->id);
        $this->putJson("/api/clients/{$this->ids['client']}", ['groupe_taxe_id' => $ailleurs])->assertUnprocessable()->assertJsonValidationErrors(['groupe_taxe_id']);
        $this->commande('client', ['groupe_taxe_id' => $ailleurs])->assertUnprocessable()->assertJsonValidationErrors(['groupe_taxe_id']);

        // Désactivé : il reste sur le document qui le porte, mais ne se choisit plus ailleurs.
        $commande = $this->commande('ong')->json('id');
        $this->chez(fn () => GroupeTaxe::whereKey($this->ids['exo'])->update(['actif' => false]));
        $this->putJson("/api/commandes-client/{$commande}", ['groupe_taxe_id' => $this->ids['exo'], 'notes' => 'Inchangé'])->assertOk();
        $this->commande('client', ['groupe_taxe_id' => $this->ids['exo']])->assertUnprocessable()->assertJsonValidationErrors(['groupe_taxe_id']);
    }
}
