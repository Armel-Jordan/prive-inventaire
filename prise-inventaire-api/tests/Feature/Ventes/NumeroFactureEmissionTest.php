<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Numéro de facture attribué à l'émission (anomalie A11, réglage de l'entreprise) : sans le réglage, rien ne change
 * (le brouillon prend le numéro suivant) ; avec, un brouillon porte « BR-{id} » et ne consomme la séquence qu'à son
 * émission, si bien qu'un brouillon supprimé ne laisse plus de trou ; vaut aussi pour la facture d'une commande.
 */
class NumeroFactureEmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chez(function () {
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);

            return [
                Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
                ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            ];
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function brouillon(): array
    {
        return $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-26',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json();
    }

    private function activer(): void
    {
        $this->putJson('/api/parametres', ['numero_facture_a_l_emission' => true])->assertOk()
            ->assertJsonPath('parametres.numero_facture_a_l_emission', true);
    }

    public function test_sans_le_reglage_le_brouillon_prend_le_numero_suivant(): void
    {
        $this->assertSame('FAC-0001', $this->brouillon()['numero']);
        $this->assertSame('FAC-0002', $this->brouillon()['numero']);
    }

    public function test_avec_le_reglage_le_numero_est_attribue_a_l_emission_sans_trou(): void
    {
        $this->activer();

        $supprime = $this->brouillon();
        $garde = $this->brouillon();
        $this->assertSame("BR-{$supprime['id']}", $supprime['numero']);
        $this->assertSame("BR-{$garde['id']}", $garde['numero']);

        // Le premier brouillon disparaît : la séquence n'a rien perdu.
        $this->deleteJson("/api/factures/{$supprime['id']}")->assertOk();
        $this->postJson("/api/factures/{$garde['id']}/emettre")->assertOk()->assertJsonPath('numero', 'FAC-0001');

        $suivant = $this->brouillon();
        $this->postJson("/api/factures/{$suivant['id']}/emettre")->assertOk()->assertJsonPath('numero', 'FAC-0002');
    }

    public function test_la_facture_d_une_commande_suit_le_reglage(): void
    {
        $this->activer();
        $commande = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-26',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();

        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json();
        $this->assertSame("BR-{$facture['id']}", $facture['numero']);
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk()->assertJsonPath('numero', 'FAC-0001');
        $this->assertSame(0, $this->chez(fn () => Facture::where('numero', 'like', 'BR-%')->count()));
    }

    public function test_le_reglage_est_propre_a_l_entreprise(): void
    {
        $this->activer();
        $autre = Tenant::factory()->create();
        $this->assertFalse((bool) app(TenantContext::class)->runAsTenant($autre->id, fn () => TenantParametres::query()->value('numero_facture_a_l_emission')));
    }
}
