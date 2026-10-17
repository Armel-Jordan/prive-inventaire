<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rapport des taxes (phase 4.8) : taxes collectées sur les ventes (taux simple ou groupe TVA + CAC, détail par taxe),
 * diminuées des notes de crédit, déductibles sur les achats ; brouillons, annulés et hors période écartés ; niveaux
 * détail, par jour et période ; sommaire par taxe ; comptes de taxes du journal une fois la comptabilisation réglée
 * (rapprochés du rapport) ; CSV et PDF ; droits et validation.
 */
class RapportTaxesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $client;

    private int $produit;

    private int $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance', 'ventes', 'achats']]);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 5, 'auto_increment' => true, 'prochain_numero' => 1]);
            $this->client = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs SA', 'adresse_facturation' => '1 rue du Port', 'ville' => 'Douala', 'code_postal' => '00000'])->id;
            $this->produit = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100])->id;
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF'])->id;
        });
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertCreated();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function facture(string $date, array $ligne, bool $emettre = true): array
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->client, 'date_facture' => $date, 'lignes' => [['produit_id' => $this->produit] + $ligne]])
            ->assertCreated()->json();
        if ($emettre) {
            $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        }

        return $facture;
    }

    private function achat(string $date, string $reference, int $quantite): array
    {
        return $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur, 'numero_fournisseur' => $reference, 'date_facture' => $date,
            'lignes' => [['produit_id' => $this->produit, 'description' => 'Ciment', 'quantite' => $quantite, 'prix_unitaire' => 100, 'taux_tva' => 19.25]],
        ])->assertCreated()->json();
    }

    private function rapport(string $niveau = 'detail'): array
    {
        return $this->getJson("/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31&niveau={$niveau}")->assertOk()->json();
    }

    public function test_taxes_collectees_et_deductibles_par_taxe_rapprochees_du_journal(): void
    {
        // Groupe camerounais : TVA 17,5 % + CAC 10 % de la TVA.
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->assertCreated()->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->assertCreated()->json('id');
        $groupe = $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'], ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated()->json('id');

        // Octobre : une vente au taux simple (1 000 HT, 192,50), une avec le groupe (1 000 HT, 175 + 17,50), un achat
        // (2 000 HT, 385), une note de crédit de 2 sacs sur la première vente (200 HT, 38,50).
        $simple = $this->facture('2026-10-05', ['quantite' => 10, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25]);
        $this->facture('2026-10-08', ['quantite' => 5, 'prix_unitaire_ht' => 200, 'groupe_taxe_id' => $groupe]);
        $this->achat('2026-10-08', 'CS-778', 20);
        $ligne = $this->chez(fn () => Facture::find($simple['id'])->lignes()->value('id'));
        $avoir = $this->postJson('/api/avoirs', ['facture_id' => $simple['id'], 'date_avoir' => '2026-10-09', 'lignes' => [['facture_ligne_id' => $ligne, 'quantite' => 2]]])
            ->assertCreated()->json();
        $this->postJson("/api/avoirs/{$avoir['id']}/emettre")->assertOk();
        // Écartés : un brouillon, un achat annulé, une vente de novembre.
        $this->facture('2026-10-20', ['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25], false);
        $annule = $this->achat('2026-10-21', 'CS-779', 3);
        $this->postJson("/api/factures-fournisseurs/{$annule['id']}/annuler")->assertOk();
        $this->facture('2026-11-02', ['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25]);

        $detail = $this->rapport();
        $cles = ['tva' => "taxe:{$tva}", 'cac' => "taxe:{$cac}", 'simple' => 'tva:19.25'];
        $this->assertSame([[$cles['tva'], 'TVA'], [$cles['cac'], 'CAC'], ['tva:19.25', 'TVA 19,25 %']], array_map(fn ($t) => [$t['cle'], $t['nom']], $detail['taxes']));
        $this->assertEquals([
            ['2026-10-05', 'vente', 'Bâtisseurs SA', 1000, 0, [$cles['simple'] => 192.5], []],
            ['2026-10-08', 'vente', 'Bâtisseurs SA', 1000, 0, [$cles['tva'] => 175, $cles['cac'] => 17.5], []],
            ['2026-10-08', 'achat', 'Cimenterie du Sud', 0, 2000, [], [$cles['simple'] => 385]],
            ['2026-10-09', 'avoir', 'Bâtisseurs SA', -200, 0, [$cles['simple'] => -38.5], []],
        ], array_map(fn ($l) => [$l['date'], $l['type'], $l['tiers'], $l['ventes_ht'], $l['achats_ht'], $l['collectee'], $l['deductible']], $detail['lignes']));
        $this->assertSame('CS-778', $detail['lignes'][2]['reference']);
        $this->assertEquals([1800, 2000, [$cles['simple'] => 154, $cles['tva'] => 175, $cles['cac'] => 17.5], [$cles['simple'] => 385]],
            [$detail['totaux']['ventes_ht'], $detail['totaux']['achats_ht'], $detail['totaux']['collectee'], $detail['totaux']['deductible']]);
        $this->assertEquals([['TVA', 175, 0, 175], ['CAC', 17.5, 0, 17.5], ['TVA 19,25 %', 154, 385, -231]],
            array_map(fn ($t) => [$t['nom'], $t['collectee'], $t['deductible'], $t['difference']], $detail['sommaire']));
        $this->assertEquals(['collectee' => 346.5, 'deductible' => 385, 'difference' => -38.5], $detail['total']);
        $this->assertNull($detail['comptes']);

        // Par jour, puis le total de la période.
        $jours = $this->rapport('jour')['lignes'];
        $this->assertSame(['2026-10-05', '2026-10-08', '2026-10-09'], array_column($jours, 'date'));
        $this->assertEquals([1000, 2000, [$cles['tva'] => 175, $cles['cac'] => 17.5], [$cles['simple'] => 385]],
            [$jours[1]['ventes_ht'], $jours[1]['achats_ht'], $jours[1]['collectee'], $jours[1]['deductible']]);
        $periode = $this->rapport('periode')['lignes'];
        $this->assertCount(1, $periode);
        $this->assertEquals([null, 1800, 2000], [$periode[0]['date'], $periode[0]['ventes_ht'], $periode[0]['achats_ht']]);

        // Une fois au journal, les comptes de taxes donnent la même taxe nette.
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', $reglages['propositions'] + ['date_debut' => '2026-09-01'])->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('erreurs', []);
        $comptes = $this->rapport()['comptes'];
        $this->assertEquals([['443', ['collectee'], 38.5, 385, 346.5], ['445', ['deductible'], 385, 0, -385]],
            array_map(fn ($c) => [$c['numero'], $c['roles'], $c['debit'], $c['credit'], $c['solde']], $comptes['lignes']));
        $this->assertEquals(-38.5, $comptes['solde']);

        // CSV et PDF.
        $csv = $this->get('/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31&format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $contenu = $csv->getContent();
        $this->assertStringContainsString('"TVA 19,25 % collectée"', $contenu);
        $this->assertStringContainsString('2026-10-08;Achat;FF-', $contenu);
        $this->assertStringContainsString("\nTotal;346,50;385,00;-38,50", $contenu);
        $pdf = $this->get('/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31&niveau=jour&format=pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_droits_et_validation(): void
    {
        $this->getJson('/api/comptabilite/etats/taxes?du=2026-10-31&au=2026-10-01')->assertUnprocessable()
            ->assertJsonPath('errors.au.0', 'La fin de la période ne peut pas précéder son début.');
        $this->getJson('/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31&niveau=mois')->assertUnprocessable()->assertJsonValidationErrors('niveau');

        // Sans le droit de voir les états comptables : refusé.
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['comptabilite.voir' => true, 'comptabilite.etats' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson('/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31')->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Voir les états comptables » (Comptabilité).');

        // Une autre entreprise ne voit que ses propres documents.
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
        $this->facture('2026-10-05', ['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25]);
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson('/api/comptabilite/etats/taxes?du=2026-10-01&au=2026-10-31')->assertOk()->assertJsonCount(0, 'lignes')->assertJsonPath('total.collectee', 0);
    }
}
