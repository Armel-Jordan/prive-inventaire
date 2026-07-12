<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C1 : les règles exists: sont scopées au tenant courant — impossible de référencer
 * l'entité d'un autre tenant (ici via la création d'un devis).
 */
class TenantExistsRuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(int $tenantId, string $code): Client
    {
        return app(TenantContext::class)->runAsTenant($tenantId, fn () => Client::create([
            'code' => $code,
            'raison_sociale' => 'Client '.$code,
            'adresse_facturation' => '1 rue Test',
            'ville' => 'Ville',
            'code_postal' => '00000',
        ]));
    }

    private function devisPayload(int $clientId): array
    {
        return [
            'client_id' => $clientId,
            'date_devis' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'lignes' => [['produit_id' => 999999, 'quantite' => 1, 'prix_unitaire' => 1]],
        ];
    }

    public function test_cannot_reference_foreign_tenant_client(): void
    {
        $tenantA = Tenant::factory()->create(['modules' => ['ventes']]);
        $adminA = AdminUser::factory()->for($tenantA)->create();
        $tenantB = Tenant::factory()->create();
        $clientB = $this->makeClient($tenantB->id, 'CLI-B');

        Sanctum::actingAs($adminA, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenantA->slug)
            ->postJson('/api/devis', $this->devisPayload($clientB->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_own_tenant_client_passes_the_exists_rule(): void
    {
        $tenantA = Tenant::factory()->create(['modules' => ['ventes']]);
        $adminA = AdminUser::factory()->for($tenantA)->create();
        $clientA = $this->makeClient($tenantA->id, 'CLI-A');

        Sanctum::actingAs($adminA, ['*']);

        // Le client du tenant courant ne doit pas déclencher d'erreur sur client_id
        // (d'autres champs peuvent échouer, mais pas la règle exists scopée).
        $this->withHeader('X-Tenant-Slug', $tenantA->slug)
            ->postJson('/api/devis', $this->devisPayload($clientA->id))
            ->assertJsonMissingValidationErrors(['client_id']);
    }
}
