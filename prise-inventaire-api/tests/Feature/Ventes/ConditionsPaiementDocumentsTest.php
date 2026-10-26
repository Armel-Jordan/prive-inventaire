<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ConditionPaiement;
use App\Models\Devis;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Condition de paiement des devis et des commandes (plan de parité fripv2, socle S4.1) : celle choisie, sinon celle du
 * client, sinon celle par défaut ; elle passe du devis à la commande puis à la facture, qui en calcule l'échéance.
 */
class ConditionsPaiementDocumentsTest extends TestCase
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
        $this->ids = $this->chez(function () {
            $condition = fn (string $code, int $jours, bool $defaut = false) => ConditionPaiement::create(['code' => $code, 'libelle' => "{$jours} jours",
                'delai_jours' => $jours, 'par_defaut' => $defaut, 'actif' => true])->id;
            $ids = ['n0' => $condition('N0', 0, true), 'n30' => $condition('N30', 30), 'n60' => $condition('N60', 60)];
            $ids['client'] = Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala',
                'code_postal' => '0', 'condition_paiement_id' => $ids['n30']])->id;
            $ids['sans'] = Client::create(['code' => 'C2', 'raison_sociale' => 'Boutique du coin', 'adresse_facturation' => '2 rue', 'ville' => 'Yaoundé', 'code_postal' => '0'])->id;
            $this->produitId = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id;

            return $ids;
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function devis(array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/devis', $autres + ['client_id' => $this->ids['client'], 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]]);
    }

    private function commande(array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/commandes-client', $autres + ['client_id' => $this->ids['client'], 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]]);
    }

    public function test_par_defaut_puis_suivie_jusqu_a_l_echeance_de_la_facture(): void
    {
        // Par défaut : celle du client, sinon celle de l'entreprise.
        $this->devis()->assertCreated()->assertJsonPath('condition_paiement_id', $this->ids['n30']);
        $this->devis(['client_id' => $this->ids['sans']])->assertCreated()->assertJsonPath('condition_paiement_id', $this->ids['n0']);
        $this->commande(['condition_paiement_id' => null])->assertCreated()->assertJsonPath('condition_paiement_id', $this->ids['n30']);

        // Choisie sur le devis : elle passe à la commande, puis à la facture, dont l'échéance s'en calcule.
        $devis = $this->devis(['condition_paiement_id' => $this->ids['n60']])->assertCreated()->assertJsonPath('condition_paiement_id', $this->ids['n60'])->json('id');
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('condition_paiement.libelle', '60 jours');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('condition_paiement_id', $this->ids['n60'])
            ->assertJsonPath('condition_paiement.libelle', '60 jours');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $this->postJson("/api/factures/commande/{$commande}", ['date' => '2026-10-20'])->assertCreated()
            ->assertJsonPath('condition_paiement_id', $this->ids['n60']);

        // Une commande d'avant (sans condition) donne toujours celle du client à sa facture.
        $ancienne = $this->commande()->assertCreated()->json('id');
        $this->chez(fn () => ComClientEntete::whereKey($ancienne)->update(['condition_paiement_id' => null]));
        $this->postJson("/api/commandes-client/{$ancienne}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$ancienne}/accepter")->assertOk();
        $this->postJson("/api/factures/commande/{$ancienne}")->assertCreated()->assertJsonPath('condition_paiement_id', $this->ids['n30']);

        // Impression du devis et de la commande.
        $this->chez(function () use ($devis, $commande) {
            $this->assertSame('60 jours', app(DocumentsVentePdf::class)->devis(Devis::findOrFail($devis))['infos']['Conditions de paiement']);
            $this->assertSame('60 jours', app(DocumentsVentePdf::class)->commande(ComClientEntete::findOrFail($commande))['infos']['Conditions de paiement']);
        });
    }

    public function test_facture_directe_copie_modification_et_referentiel(): void
    {
        // Devis facturé sans commande : la facture prend sa condition et en calcule l'échéance.
        $devis = $this->devis(['condition_paiement_id' => $this->ids['n60']])->json('id');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $facture = $this->postJson("/api/devis/{$devis}/facturer", ['date' => '2026-10-20'])->assertCreated()
            ->assertJsonPath('facture.condition_paiement_id', $this->ids['n60'])->json('facture');
        $this->assertSame('2026-12-19', substr($facture['date_echeance'], 0, 10));

        // La copie garde la condition.
        $this->postJson("/api/devis/{$devis}/copier")->assertCreated()->assertJsonPath('devis.condition_paiement_id', $this->ids['n60']);

        // Modification : changée si envoyée ; vide, celle du client ; non envoyée, rien ne change.
        $commande = $this->commande()->json('id');
        $this->putJson("/api/commandes-client/{$commande}", ['condition_paiement_id' => $this->ids['n60']])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('condition_paiement_id', $this->ids['n60']);
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Fragile'])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('condition_paiement_id', $this->ids['n60']);
        $this->putJson("/api/commandes-client/{$commande}", ['condition_paiement_id' => null])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('condition_paiement_id', $this->ids['n30']);

        // Une condition d'une autre entreprise est refusée.
        $autre = Tenant::factory()->create();
        $etrangere = app(TenantContext::class)->runAsTenant($autre->id, fn () => ConditionPaiement::create(['code' => 'X', 'libelle' => 'X', 'delai_jours' => 5])->id);
        $this->devis(['condition_paiement_id' => $etrangere])->assertUnprocessable()->assertJsonValidationErrors(['condition_paiement_id']);

        // Portée seulement par un devis, elle ne se supprime pas.
        $this->chez(fn () => Client::whereKey($this->ids['client'])->update(['condition_paiement_id' => null]));
        $this->deleteJson("/api/referentiels/conditions_paiement/{$this->ids['n60']}")->assertStatus(422)
            ->assertJsonPath('message', 'Cette condition de paiement est utilisée par des clients ou des documents de vente : désactivez-la plutôt.');
    }
}
