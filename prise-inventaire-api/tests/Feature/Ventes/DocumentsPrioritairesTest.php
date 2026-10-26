<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Documents prioritaires (plan de parité fripv2, socle S4.1, l'étoile) : un devis, une commande, une facture ou un bon de
 * livraison se marque prioritaire, même clos, avec le droit de modifier le document ; filtre des listes ; la marque
 * passe du devis à la commande, puis à la facture et au bon de livraison.
 */
class DocumentsPrioritairesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chez(fn () => [
            Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function devis(): int
    {
        return $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->json('id');
    }

    public function test_marque_filtre_et_suite_jusqu_au_bon_de_livraison(): void
    {
        $devis = $this->devis();
        $autre = $this->devis();

        // Marqué, puis filtré dans la liste ; la valeur est exigée.
        $this->putJson("/api/devis/{$devis}/prioritaire", ['prioritaire' => true])->assertOk()->assertJsonPath('prioritaire', true);
        $this->putJson("/api/devis/{$devis}/prioritaire", [])->assertUnprocessable()->assertJsonValidationErrors(['prioritaire']);
        $this->assertSame([$devis], array_column($this->getJson('/api/devis?prioritaire=1')->assertOk()->json('data'), 'id'));
        $this->assertCount(2, $this->getJson('/api/devis')->json('data'));
        $this->getJson("/api/devis/{$autre}")->assertJsonPath('prioritaire', false);

        // La marque suit : commande, facture, bon de livraison.
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('prioritaire', true);
        $this->assertSame([$commande], array_column($this->getJson('/api/commandes-client?prioritaire=1')->json('data'), 'id'));
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->assertJsonPath('prioritaire', true)->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->assertJsonPath('prioritaire', true)->json('id');
        $this->assertSame([$bon], array_column($this->getJson('/api/bons-livraison?prioritaire=1')->json('data'), 'id'));

        // Retirée sur le bon seulement.
        $this->putJson("/api/bons-livraison/{$bon}/prioritaire", ['prioritaire' => false])->assertOk()->assertJsonPath('prioritaire', false);
        $this->assertSame([], $this->getJson('/api/bons-livraison?prioritaire=1')->json('data'));
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('prioritaire', true);

        // Devis facturé directement : la facture est prioritaire aussi.
        $this->putJson("/api/devis/{$autre}/prioritaire", ['prioritaire' => true])->assertOk();
        $this->postJson("/api/devis/{$autre}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$autre}/accepter")->assertOk();
        $this->postJson("/api/devis/{$autre}/facturer")->assertCreated()->assertJsonPath('facture.prioritaire', true);
    }

    public function test_document_clos_droits_et_isolation(): void
    {
        // Un document clos (facture payée) se marque aussi.
        $facture = $this->chez(fn () => Facture::create(['numero' => 'FAC-1', 'client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'statut' => 'payee',
            'montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'reste_a_payer' => 0])->id);
        $this->putJson("/api/factures/{$facture}/prioritaire", ['prioritaire' => true])->assertOk();
        $this->assertSame([$facture], array_column($this->getJson('/api/factures?prioritaire=1')->json('data'), 'id'));

        // Il faut le droit de modifier le document : consulter ne suffit pas.
        $devis = $this->devis();
        $lecteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$lecteur->id}", ['groupes' => [], 'exceptions' => ['devis.voir' => true, 'devis.modifier' => false]])->assertOk();
        $this->agir($lecteur);
        $this->putJson("/api/devis/{$devis}/prioritaire", ['prioritaire' => true])->assertForbidden();
        $this->assertFalse($this->chez(fn () => Devis::findOrFail($devis)->prioritaire));

        // Le document d'une autre entreprise est introuvable.
        $ailleurs = Tenant::factory()->create(['modules' => ['ventes']]);
        $etranger = app(TenantContext::class)->runAsTenant($ailleurs->id, fn () => Facture::create(['numero' => 'FAC-X', 'client_id' => $this->clientId,
            'date_facture' => '2026-10-16', 'statut' => 'brouillon', 'montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0, 'reste_a_payer' => 0])->id);
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
        $this->putJson("/api/factures/{$etranger}/prioritaire", ['prioritaire' => true])->assertNotFound();
    }
}
