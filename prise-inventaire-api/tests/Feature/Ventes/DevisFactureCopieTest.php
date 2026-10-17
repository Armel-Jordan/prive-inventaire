<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Devise;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Devis facturé directement et devis copié (phase 1.3) : facture brouillon issue d'un devis accepté (mêmes lignes,
 * prix, devise, taux, notes, client de passage), une seule conversion (commande ou facture), droits ; copie en
 * nouveau brouillon daté du jour, même durée de validité, devise au taux du jour, droits.
 */
class DevisFactureCopieTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function devis(array $autres = [], bool $accepter = true): int
    {
        $id = $this->postJson('/api/devis', $autres + ['client_id' => $this->clientId, 'date_devis' => '2026-10-10', 'date_validite' => '2026-11-09', 'notes' => 'Livraison incluse.',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 3, 'prix_unitaire' => 120], ['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 50]]])
            ->assertCreated()->json('id');
        if ($accepter) {
            $this->postJson("/api/devis/{$id}/envoyer")->assertOk();
            $this->postJson("/api/devis/{$id}/accepter")->assertOk();
        }

        return $id;
    }

    public function test_devis_accepte_facture_directement_une_seule_fois(): void
    {
        $this->postJson('/api/devis/'.$this->devis([], false).'/facturer')->assertUnprocessable()->assertJsonPath('message', 'Seuls les devis acceptés peuvent être facturés.');

        $devis = $this->devis();
        $facture = $this->postJson("/api/devis/{$devis}/facturer")->assertCreated()->assertJsonPath('facture.statut', 'brouillon')
            ->assertJsonPath('facture.notes', 'Livraison incluse.')->assertJsonPath('facture.client_id', $this->clientId)->json('facture');
        $this->assertEquals(410, $facture['montant_ht']);
        $this->assertSame([[3, 120.0], [1, 50.0]], array_map(fn ($l) => [(int) $l['quantite'], (float) $l['prix_unitaire_ht']], $facture['lignes']));
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('facture.numero', $facture['numero'])->assertJsonPath('statut', 'accepte');

        // Une seule conversion : ni une seconde facture, ni une commande.
        $this->postJson("/api/devis/{$devis}/facturer")->assertUnprocessable()->assertJsonPath('facture.numero', $facture['numero']);
        $this->postJson("/api/devis/{$devis}/convertir")->assertUnprocessable()->assertJsonPath('facture.numero', $facture['numero']);
        // Un devis converti en commande n'est plus facturable.
        $autre = $this->devis();
        $commande = $this->postJson("/api/devis/{$autre}/convertir")->assertOk()->json('commande.numero');
        $this->postJson("/api/devis/{$autre}/facturer")->assertUnprocessable()->assertJsonPath('commande.numero', $commande);
    }

    public function test_devise_client_de_passage_et_droits_de_la_facturation(): void
    {
        // Devis en dollars au taux de 600 : la facture garde la devise et le taux du devis.
        $passage = $this->getJson('/api/clients/de-passage')->json('id');
        $devis = $this->devis(['client_id' => $passage, 'passage_nom' => 'Jean Dupont', 'devise' => 'USD', 'taux_change' => 600]);
        $facture = $this->postJson("/api/devis/{$devis}/facturer")->assertCreated()->json('facture');
        $this->assertSame(['USD', 600.0, 'Jean Dupont'], [$facture['devise'], (float) $facture['taux_change'], $facture['passage_nom']]);

        // Facturer demande le droit de convertir le devis et celui de créer une facture.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['factures.creer' => false]])->assertOk();
        $suivant = $this->devis();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/devis/{$suivant}/facturer")->assertForbidden()->assertJsonPath('module', 'factures');
        $this->postJson("/api/devis/{$suivant}/convertir")->assertOk();
    }

    public function test_copie_du_devis_en_nouveau_brouillon(): void
    {
        $this->chezLeTenant(fn () => Devise::create(['code' => 'USD', 'libelle' => 'Dollar US', 'taux' => 610]));
        $devis = $this->devis(['devise' => 'USD', 'taux_change' => 600]);
        $original = $this->chezLeTenant(fn () => Devis::findOrFail($devis));

        $copie = $this->postJson("/api/devis/{$devis}/copier")->assertCreated()->json('devis');
        $this->assertNotSame($original->numero, $copie['numero']);
        $this->assertSame(['brouillon', 'Livraison incluse.', 'USD', 610.0], [$copie['statut'], $copie['notes'], $copie['devise'], (float) $copie['taux_change']]);
        // Daté du jour, même durée de validité (30 jours) ; mêmes lignes et total.
        $this->assertSame(['2026-10-16', '2026-11-15'], [substr($copie['date_devis'], 0, 10), substr($copie['date_validite'], 0, 10)]);
        $this->assertEquals(410, $copie['montant_total']);
        $this->assertCount(2, $copie['lignes']);
        $this->assertNull($copie['commande_id'] ?? null);

        // Copier demande le droit de créer un devis.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['devis.creer' => false]])->assertOk();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/devis/{$devis}/copier")->assertForbidden();
    }
}
