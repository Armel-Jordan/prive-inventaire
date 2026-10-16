<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devise;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ventes en plusieurs devises (phase 1.5) : devise du client reprise par ses documents (taux du référentiel ou
 * saisi), devis → commande → facture → note de crédit dans la même devise, acomptes et imputations de même devise,
 * encours, comptes clients, fiche client et tableau de bord dans la devise de l'entreprise, PDF et courriel dans la
 * devise du document.
 */
class MultiDevisesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientUsd;

    private int $clientLocal;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientUsd, $this->clientLocal, $this->produitId] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Ma Société', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);
            // 1 USD = 600 XAF ; 1 EUR = 655,957 XAF (référentiel des devises).
            Devise::create(['code' => 'USD', 'libelle' => 'Dollar américain', 'symbole' => '$', 'decimales' => 2, 'taux' => 600, 'actif' => true]);
            Devise::create(['code' => 'EUR', 'libelle' => 'Euro', 'symbole' => '€', 'decimales' => 2, 'taux' => 655.957, 'actif' => true]);

            return [
                Client::create(['code' => 'CLI-US', 'raison_sociale' => 'Client USD', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'ap@client.us'])->id,
                Client::create(['code' => 'CLI-CM', 'raison_sociale' => 'Client local', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
                ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            ];
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->putJson("/api/clients/{$this->clientUsd}", ['devise' => 'usd'])->assertOk()->assertJsonPath('devise', 'USD');
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Facture de 100 HT à 20 % (120 TTC dans sa devise), émise. */
    private function facture(int $client, array $autres = []): array
    {
        $facture = $this->postJson('/api/factures', $autres + [
            'client_id' => $client, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

        return $facture;
    }

    public function test_facture_dans_la_devise_du_client_et_montants_convertis(): void
    {
        $facture = $this->facture($this->clientUsd);
        $this->assertEquals(['USD', 600], [$facture['devise'], $facture['taux_change']]);

        // Encours, comptes clients, fiche client : dans la devise de l'entreprise (120 USD × 600).
        $this->assertEquals(72000, $this->chezLeTenant(fn () => Client::find($this->clientUsd)->encours_actuel));
        $this->assertEquals(72000, $this->getJson('/api/comptes-clients')->assertOk()->json('totaux.total'));
        $releve = $this->getJson("/api/comptes-clients/{$this->clientUsd}/releve")->assertOk()->json();
        $this->assertSame('Facture FAC-00001 (120,00 USD)', $releve['lignes'][0]['libelle']);
        $this->assertEquals([72000, 'USD', 120], [$releve['factures_dues'][0]['reste'], $releve['factures_dues'][0]['devise'], $releve['factures_dues'][0]['reste_devise']]);
        $this->assertEquals(60000, $this->getJson("/api/clients/{$this->clientUsd}/synthese")->assertOk()->json('chiffre_affaires.total'));

        // Paiement en USD : le reste et l'encours suivent, au taux de la facture.
        $this->postJson("/api/factures/{$facture['id']}/paiement", ['montant' => 60, 'date_paiement' => '2026-10-16', 'mode_paiement' => 'virement'])->assertOk();
        $this->assertEquals(36000, $this->chezLeTenant(fn () => Client::find($this->clientUsd)->encours_actuel));
        $this->assertEquals([72000, 36000, 36000], array_values(array_intersect_key(
            $this->getJson("/api/comptes-clients/{$this->clientUsd}/releve")->json(), array_flip(['total_debit', 'total_credit', 'solde_final']))));
        $this->assertEquals(36000, $this->getJson('/api/dashboard?period=month')->assertOk()->json('finance.montant_impaye'));

        // Taux saisi, devise sans taux connu, devise de l'entreprise.
        $this->assertEquals(['EUR', 650], array_values(array_intersect_key($this->facture($this->clientLocal, ['devise' => 'EUR', 'taux_change' => 650]), array_flip(['devise', 'taux_change']))));
        $this->postJson('/api/factures', ['client_id' => $this->clientLocal, 'date_facture' => '2026-10-16', 'devise' => 'GBP',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])
            ->assertUnprocessable()->assertJsonPath('errors.taux_change.0', 'Indiquez le taux de change GBP → XAF.');
        $locale = $this->facture($this->clientUsd, ['devise' => 'XAF']);
        $this->assertEquals([null, 1], [$locale['devise'], $locale['taux_change']]);
    }

    public function test_devis_commande_facture_note_de_credit_et_acomptes_dans_la_meme_devise(): void
    {
        $devis = $this->postJson('/api/devis', [
            'client_id' => $this->clientUsd, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'taux_change' => 610,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]],
        ])->assertCreated()->json();
        $this->assertEquals(['USD', 610], [$devis['devise'], $devis['taux_change']]);
        $this->postJson("/api/devis/{$devis['id']}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis['id']}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis['id']}/convertir")->assertOk()->json('commande');
        $this->assertEquals(['USD', 610], [$commande['devise'], $commande['taux_change']]);
        $commandeId = $commande['id'];
        $this->postJson("/api/commandes-client/{$commandeId}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commandeId}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commandeId}")->assertCreated()->json();
        $this->assertEquals(['USD', 610], [$facture['devise'], $facture['taux_change']]);
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

        // Note de crédit : dans la devise de sa facture ; pas d'imputation sur une facture d'une autre devise.
        $ligne = $this->getJson("/api/factures/{$facture['id']}/avoir")->json('lignes.0.facture_ligne_id');
        $avoir = $this->postJson('/api/avoirs', ['facture_id' => $facture['id'], 'date_avoir' => '2026-10-16', 'lignes' => [['facture_ligne_id' => $ligne, 'quantite' => 1]]])
            ->assertCreated()->json();
        $this->assertEquals(['USD', 610], [$avoir['devise'], $avoir['taux_change']]);

        // Acompte du client (USD, taux du référentiel) : appliqué à une facture en USD, pas à une facture locale.
        $acompte = $this->postJson('/api/acomptes', ['client_id' => $this->clientUsd, 'date_acompte' => '2026-10-16', 'mode' => 'virement', 'montant' => 50])
            ->assertCreated()->json();
        $this->assertEquals(['USD', 600], [$acompte['devise'], $acompte['taux_change']]);
        $locale = $this->facture($this->clientUsd, ['devise' => 'XAF']);
        $this->postJson("/api/acomptes/{$acompte['id']}/appliquer", ['facture_id' => $locale['id'], 'montant' => 10, 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('facture_id');
        $this->postJson("/api/acomptes/{$acompte['id']}/appliquer", ['facture_id' => $facture['id'], 'montant' => 10, 'date' => '2026-10-16'])->assertOk();

        // PDF et courriel dans la devise du document.
        $html = $this->chezLeTenant(fn () => view('pdf.document-vente', app(DocumentsVentePdf::class)->facture(Facture::find($facture['id'])))->render());
        $this->assertStringContainsString('1 USD = 610 XAF', $html);
        $this->assertStringContainsString('$', $html);
        $courriel = $this->getJson("/api/courriels/preparer?type=facture&document_id={$facture['id']}")->assertOk()->json('contenu');
        $this->assertStringContainsString('USD', $courriel);
    }
}
