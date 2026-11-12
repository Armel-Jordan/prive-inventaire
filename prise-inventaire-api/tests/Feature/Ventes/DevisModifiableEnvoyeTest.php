<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Devis modifiable jusqu'à la réponse (plan de parité fripv2, DE2) : brouillon ou envoyé sans réponse, sans changer le
 * client d'un devis envoyé ; plus rien une fois accepté ou refusé.
 */
class DevisModifiableEnvoyeTest extends TestCase
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
            'autre' => Client::create(['code' => 'C2', 'raison_sociale' => 'Bâtiments Ngono', 'adresse_facturation' => 'Rue', 'ville' => 'Yaoundé', 'code_postal' => '0'])->id,
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function saisie(int $client, int $quantite, ?int $ligne = null): array
    {
        return ['client_id' => $client, 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [array_filter(['id' => $ligne, 'produit_id' => $this->ids['ciment'], 'quantite' => $quantite, 'prix_unitaire' => 100])]];
    }

    public function test_envoye_modifiable_jusqu_a_la_reponse(): void
    {
        $devis = $this->postJson('/api/devis', $this->saisie($this->ids['client'], 2))->assertCreated()->json();
        $ligne = $devis['lignes'][0]['id'];
        $this->postJson("/api/devis/{$devis['id']}/envoyer")->assertOk();

        // Envoyé : les lignes se modifient, le statut reste « envoyé ».
        $this->putJson("/api/devis/{$devis['id']}", $this->saisie($this->ids['client'], 5, $ligne))->assertOk()
            ->assertJsonPath('statut', 'envoye')->assertJsonPath('lignes.0.quantite', 5);
        $this->assertEquals(500, $this->getJson("/api/devis/{$devis['id']}")->json('montant_total'));

        // Mais pas son client.
        $this->putJson("/api/devis/{$devis['id']}", $this->saisie($this->ids['autre'], 5, $ligne))->assertUnprocessable()
            ->assertJsonPath('message', "Le client d'un devis envoyé ne se change pas.");

        // Accepté : plus de modification.
        $this->postJson("/api/devis/{$devis['id']}/accepter")->assertOk();
        $this->putJson("/api/devis/{$devis['id']}", $this->saisie($this->ids['client'], 1, $ligne))->assertUnprocessable()
            ->assertJsonPath('message', 'Seuls les devis en brouillon ou envoyés sans réponse peuvent être modifiés.');
        $this->assertEquals(500, $this->getJson("/api/devis/{$devis['id']}")->json('montant_total'));
    }

    public function test_brouillon_change_de_client_et_refuse_reste_fige(): void
    {
        $devis = $this->postJson('/api/devis', $this->saisie($this->ids['client'], 2))->assertCreated()->json('id');
        // Brouillon : le client se change toujours.
        $this->putJson("/api/devis/{$devis}", $this->saisie($this->ids['autre'], 2))->assertOk()->assertJsonPath('client_id', $this->ids['autre']);

        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/refuser", ['motif' => 'Trop cher'])->assertOk();
        $this->putJson("/api/devis/{$devis}", $this->saisie($this->ids['autre'], 3))->assertUnprocessable();
    }
}
