<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Liens « Copié de » et « Copies » (plan de parité fripv2, socle S4.3) : un document créé avec `copie_de` garde son
 * origine ; la copie d'un devis aussi ; les liens se lisent avec le droit de voir le document.
 */
class LiensCopiesTest extends TestCase
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
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), $this->tenant);
    }

    private function agir(AdminUser $compte, Tenant $tenant): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function devis(): array
    {
        return $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 2, 'prix_unitaire' => 100]]])->assertCreated()->json();
    }

    public function test_copie_de_et_copies(): void
    {
        $devis = $this->devis();
        $ligne = [['produit_id' => $this->ids['ciment'], 'quantite' => 2, 'prix_unitaire_ht' => 100]];

        // Une facture et une commande créées depuis le devis (« Copier vers »).
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-12', 'lignes' => $ligne,
            'copie_de' => ['type' => 'devis', 'id' => $devis['id']]])->assertCreated()->json();
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-11-12', 'lignes' => $ligne,
            'copie_de' => ['type' => 'devis', 'id' => $devis['id']]])->assertCreated()->json();
        // La copie d'un devis note aussi son origine.
        $copie = $this->postJson("/api/devis/{$devis['id']}/copier")->assertCreated()->json('devis');

        $liens = $this->getJson("/api/copies-documents/devis/{$devis['id']}")->assertOk()->assertJsonPath('copie_de', null)->json('copies');
        $this->assertSame(
            [['devis', $copie['numero']], ['commande_client', $commande['numero']], ['facture', $facture['numero']]],
            array_map(fn ($l) => [$l['type'], $l['numero']], $liens),
        );
        $this->getJson("/api/copies-documents/facture/{$facture['id']}")->assertOk()
            ->assertJsonPath('copie_de.type', 'devis')->assertJsonPath('copie_de.numero', $devis['numero'])->assertJsonPath('copies', []);
        $this->getJson("/api/copies-documents/commande_client/{$commande['id']}")->assertOk()->assertJsonPath('copie_de.id', $devis['id']);

        // Une facture sans origine, et un document supprimé qui disparaît des liens.
        $seule = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-12', 'lignes' => $ligne])->assertCreated()->json('id');
        $this->getJson("/api/copies-documents/facture/{$seule}")->assertOk()->assertJsonPath('copie_de', null);
        $this->deleteJson("/api/devis/{$copie['id']}")->assertOk();
        $this->assertCount(2, $this->getJson("/api/copies-documents/devis/{$devis['id']}")->json('copies'));
    }

    public function test_controles_droits_et_isolation(): void
    {
        $devis = $this->devis();
        $ligne = [['produit_id' => $this->ids['ciment'], 'quantite' => 1, 'prix_unitaire_ht' => 100]];
        $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-12', 'lignes' => $ligne,
            'copie_de' => ['type' => 'bon', 'id' => 1]])->assertUnprocessable()->assertJsonValidationErrors(['copie_de.type']);
        $this->getJson('/api/copies-documents/devis/999')->assertNotFound();
        $this->getJson('/api/copies-documents/bon/1')->assertNotFound();

        // Avec le droit de voir les factures : permis ; refusé par une exception : 403.
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-12', 'lignes' => $ligne])->json('id');
        $responsable = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->agir($responsable, $this->tenant);
        $this->getJson("/api/copies-documents/facture/{$facture}")->assertOk();
        DB::table('admin_user_permissions')->insert(['admin_user_id' => $responsable->id, 'permission' => 'factures.voir', 'accorde' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->agir($responsable->fresh(), $this->tenant);
        $this->getJson("/api/copies-documents/facture/{$facture}")->assertForbidden();

        // Une autre entreprise : le document n'existe pas pour elle, et son origine ne peut pas être un devis d'ailleurs.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $client = app(TenantContext::class)->runAsTenant($autre->id, fn () => Client::create(['code' => 'X', 'raison_sociale' => 'Autre', 'adresse_facturation' => 'Rue', 'ville' => 'Yaoundé', 'code_postal' => '0'])->id);
        $produit = app(TenantContext::class)->runAsTenant($autre->id, fn () => ProduitTenant::create(['numero' => 'P', 'description' => 'P', 'prix_unitaire' => 1])->id);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/copies-documents/devis/{$devis['id']}")->assertNotFound();
        $etrangere = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-11-12', 'lignes' => [['produit_id' => $produit, 'quantite' => 1, 'prix_unitaire_ht' => 1]],
            'copie_de' => ['type' => 'devis', 'id' => $devis['id']]])->assertCreated()->json('id');
        $this->getJson("/api/copies-documents/facture/{$etrangere}")->assertOk()->assertJsonPath('copie_de', null);
    }
}
