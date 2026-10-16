<?php

namespace Tests\Feature\Taxes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\DocumentTaxe;
use App\Models\GroupeTaxe;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Groupes de taxes appliqués aux documents (plan de parité fripv2, phase 0.4b) : seulement
 * quand une ligne en reçoit un ou qu'un groupe par défaut existe ; sinon calcul inchangé.
 */
class TaxesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            foreach (['commande' => 'CMD', 'facture' => 'FAC'] as $entite => $prefixe) {
                Configuration::create(['entite' => $entite, 'prefixe' => $prefixe, 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
            }
            $client = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000']);
            $produit = ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Produit test', 'prix_unitaire' => 100]);

            return [$client->id, $produit->id];
        });

        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Groupe TVA 17,5 % + CAC 10 % de la TVA (19,25 %). */
    private function groupeCameroun(bool $parDefaut = true): int
    {
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->json('id');

        return $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'par_defaut' => $parDefaut, 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated()->json('id');
    }

    private function commande(array $lignes): array
    {
        return $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId,
            'date_commande' => '2026-10-15',
            'lignes' => $lignes,
        ])->assertCreated()->json();
    }

    private function ligne(array $autres = []): array
    {
        // 10 × 100 = 1 000 HT par défaut ; $autres l'emporte (l'union + garde les clés de gauche).
        return $autres + ['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100];
    }

    public function test_sans_groupe_le_calcul_historique_est_inchange(): void
    {
        $commande = $this->commande([$this->ligne()]);
        $this->assertEquals(20, (float) $commande['lignes'][0]['taux_tva']);
        $this->assertEquals(1200, (float) $commande['lignes'][0]['montant_ttc']);
        $this->assertSame([], $this->getJson("/api/commandes-client/{$commande['id']}")->json('recapitulatif_taxes'));

        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-15', 'lignes' => [$this->ligne()]])->assertCreated();
        $this->assertEquals(200, (float) $facture->json('montant_tva'));
        $this->assertSame(0, DocumentTaxe::count());
    }

    public function test_groupe_par_defaut_applique_aux_lignes_sans_taux(): void
    {
        $this->groupeCameroun();
        $commande = $this->commande([$this->ligne()]);

        $this->assertEquals(19.25, (float) $commande['lignes'][0]['taux_tva']);
        $this->assertEquals(1192.5, (float) $commande['lignes'][0]['montant_ttc']);
        $this->assertEquals(192.5, (float) $commande['montant_tva']);

        $recap = $this->getJson("/api/commandes-client/{$commande['id']}")->json('recapitulatif_taxes');
        $this->assertSame(['TVA', 'CAC'], array_column($recap, 'nom'));
        $this->assertEquals([175, 17.5], array_column($recap, 'montant'));
        $this->assertEquals([1000, 175], array_column($recap, 'base'));
    }

    public function test_taux_saisi_garde_le_calcul_historique(): void
    {
        $this->groupeCameroun();
        $commande = $this->commande([$this->ligne(['taux_tva' => 10])]);

        $this->assertEquals(10, (float) $commande['lignes'][0]['taux_tva']);
        $this->assertEquals(1100, (float) $commande['lignes'][0]['montant_ttc']);
        $this->assertNull($commande['lignes'][0]['groupe_taxe_id']);
    }

    public function test_groupe_choisi_par_ligne(): void
    {
        $this->groupeCameroun();
        $exonere = $this->postJson('/api/groupes-taxes', ['code' => 'EXO', 'nom' => 'Exonéré', 'lignes' => []])->json('id');

        $commande = $this->commande([$this->ligne(['groupe_taxe_id' => $exonere]), $this->ligne()]);

        $this->assertEquals(1000, (float) $commande['lignes'][0]['montant_ttc']);
        $this->assertEquals(1192.5, (float) $commande['lignes'][1]['montant_ttc']);
        $this->assertEquals(192.5, (float) $commande['montant_tva']);
    }

    public function test_facture_depuis_commande_reprend_le_detail(): void
    {
        $this->groupeCameroun();
        $commande = $this->commande([$this->ligne(), $this->ligne(['quantite' => 2])]);
        ComClientEntete::withoutTenantScope()->whereKey($commande['id'])->update(['statut' => 'acceptee']);

        $facture = $this->postJson("/api/factures/commande/{$commande['id']}")->assertCreated();
        $recapCommande = $this->getJson("/api/commandes-client/{$commande['id']}")->json('recapitulatif_taxes');
        $recapFacture = $this->getJson('/api/factures/'.$facture->json('id'))->assertOk()->json('recapitulatif_taxes');

        $this->assertEquals($recapCommande, $recapFacture);
        // 1 200 HT : TVA 210 (175 + 35), CAC 21 (17,50 + 3,50).
        $this->assertEquals([210, 21], array_column($recapFacture, 'montant'));
        $this->assertEquals(231, (float) $facture->json('montant_tva'));
    }

    public function test_facture_saisie_avec_groupe_par_defaut(): void
    {
        $this->groupeCameroun();
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-15', 'lignes' => [$this->ligne()]])
            ->assertCreated();

        $this->assertEquals(192.5, (float) $facture->json('montant_tva'));
        $this->assertEquals(1192.5, (float) $facture->json('montant_ttc'));
        $this->assertEquals([175, 17.5], array_column($this->getJson('/api/factures/'.$facture->json('id'))->json('recapitulatif_taxes'), 'montant'));
    }

    public function test_modifier_une_commande_remplace_le_detail(): void
    {
        $this->groupeCameroun();
        $commande = $this->commande([$this->ligne(), $this->ligne()]);
        $this->assertSame(4, DocumentTaxe::count());

        $this->putJson("/api/commandes-client/{$commande['id']}", ['lignes' => [$this->ligne(['quantite' => 1])]])->assertOk();

        $this->assertSame(2, DocumentTaxe::count());
        $this->assertEquals([17.5, 1.75], array_column($this->getJson("/api/commandes-client/{$commande['id']}")->json('recapitulatif_taxes'), 'montant'));
    }

    public function test_groupe_dun_autre_tenant_refuse(): void
    {
        $autre = Tenant::factory()->create();
        $groupeAutre = app(TenantContext::class)->runAsTenant($autre->id, fn () => GroupeTaxe::create(['code' => 'X', 'nom' => 'Ailleurs']));

        $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId,
            'date_commande' => '2026-10-15',
            'lignes' => [$this->ligne(['groupe_taxe_id' => $groupeAutre->id])],
        ])->assertStatus(422)->assertJsonValidationErrors(['lignes.0.groupe_taxe_id']);
    }
}
