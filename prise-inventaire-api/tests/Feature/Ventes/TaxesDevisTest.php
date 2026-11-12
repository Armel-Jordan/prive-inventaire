<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\DocumentTaxe;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TauxTaxe;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Taxes sur le devis (plan de parité fripv2, DE1) : chaque ligne a ses taxes (groupe du devis, sinon groupe par défaut,
 * sinon taux par défaut de l'entreprise), le devis ses totaux HT, taxes et TTC, repris au PDF ; la commande issue du
 * devis a le même total TTC.
 */
class TaxesDevisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            TauxTaxe::definir($this->tenant->id, 19.25);

            return [
                'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
                'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
                'fer' => ProduitTenant::create(['numero' => 'FER', 'description' => 'Fer', 'prix_unitaire' => 50])->id,
            ];
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function devis(array $entete = []): array
    {
        return $this->postJson('/api/devis', $entete + ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10', 'lignes' => [
            ['produit_id' => $this->ids['ciment'], 'quantite' => 2, 'prix_unitaire' => 100],
            ['produit_id' => $this->ids['fer'], 'quantite' => 1, 'prix_unitaire' => 50],
        ]])->assertCreated()->json();
    }

    /** @return array<int, float> */
    private function totaux(array $document): array
    {
        return array_map(fn ($c) => (float) $document[$c], ['montant_ht', 'montant_tva', 'montant_ttc']);
    }

    public function test_taux_par_defaut_modification_et_conversion(): void
    {
        // Sans groupe : le taux par défaut de l'entreprise (19,25 %), ligne par ligne.
        $devis = $this->devis();
        $this->assertSame([250.0, 48.13, 298.13], $this->totaux($devis));
        $this->assertEquals(250, $devis['montant_total']);
        $this->assertEquals([19.25, 38.5, 238.5], [$devis['lignes'][0]['taux_tva'], (float) $devis['lignes'][0]['montant_tva'], (float) $devis['lignes'][0]['montant_ttc']]);

        // Modifié : les taxes suivent les lignes.
        $ligne = $devis['lignes'][0]['id'];
        $modifie = $this->putJson("/api/devis/{$devis['id']}", ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['id' => $ligne, 'produit_id' => $this->ids['ciment'], 'quantite' => 4, 'prix_unitaire' => 100]]])->assertOk()->json();
        $this->assertSame([400.0, 77.0, 477.0], $this->totaux($modifie));

        // La commande tirée du devis a le même total TTC.
        $this->postJson("/api/devis/{$devis['id']}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis['id']}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis['id']}/convertir", ['date_commande' => '2026-11-12'])->assertSuccessful()->json('commande.id');
        $this->assertEquals(477, (float) $this->getJson("/api/commandes-client/{$commande}")->json('montant_ttc'));
    }

    public function test_groupe_du_devis_detail_et_pdf(): void
    {
        $tps = $this->postJson('/api/taxes', ['nom' => 'TPS', 'taux' => 5])->json('id');
        $tvq = $this->postJson('/api/taxes', ['nom' => 'TVQ', 'taux' => 9.975])->json('id');
        $groupe = $this->postJson('/api/groupes-taxes', ['code' => 'QC', 'nom' => 'Québec', 'lignes' => [
            ['tenant_taxe_id' => $tps, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $tvq, 'applique_sur' => 'base'],
        ]])->assertCreated()->json('id');

        $devis = $this->devis(['groupe_taxe_id' => $groupe]);
        $this->assertSame([250.0, 37.44, 287.44], $this->totaux($devis));
        $this->assertSame($groupe, $devis['lignes'][0]['groupe_taxe_id']);

        // Détail par taxe pour le PDF : TPS et TVQ.
        $totaux = app(TenantContext::class)->runAsTenant($this->tenant->id, function () use ($devis) {
            $this->assertSame(4, DocumentTaxe::where('document', 'devis')->count());

            return app(DocumentsVentePdf::class)->devis(Devis::findOrFail($devis['id']))['totaux'];
        });
        $this->assertSame(['Sous-total HT', 'TPS (5 %)', 'TVQ (9,975 %)', 'Total TTC'], array_column($totaux, 0));
        $this->assertEquals(287.44, end($totaux)[1]);
        $this->assertStringStartsWith('%PDF', $this->get("/api/devis/{$devis['id']}/pdf")->assertOk()->getContent());

        // Une ligne retirée emporte son détail.
        $this->putJson("/api/devis/{$devis['id']}", ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10', 'groupe_taxe_id' => $groupe,
            'lignes' => [['id' => $devis['lignes'][0]['id'], 'produit_id' => $this->ids['ciment'], 'quantite' => 2, 'prix_unitaire' => 100]]])->assertOk();
        $this->assertSame(2, app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => DocumentTaxe::where('document', 'devis')->count()));
    }

    public function test_devis_d_avant_reste_hors_taxes(): void
    {
        $id = $this->devis()['id'];
        // Un devis enregistré avant les taxes (colonnes vides) : le PDF garde « Total HT » et la mention.
        app(TenantContext::class)->runAsTenant($this->tenant->id, function () use ($id) {
            Devis::whereKey($id)->update(['montant_ht' => null, 'montant_tva' => null, 'montant_ttc' => null]);
            $donnees = app(DocumentsVentePdf::class)->devis(Devis::findOrFail($id));
            $this->assertSame([['Total HT', 250.0, true]], $donnees['totaux']);
            $this->assertSame('Montants hors taxes.', $donnees['mention']);
        });
        $this->getJson("/api/devis/{$id}")->assertOk()->assertJsonPath('montant_ttc', null);
    }
}
