<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C5 : les règles unique: sont scopées par tenant — même code réutilisable entre
 * entreprises, mais unique à l'intérieur d'une entreprise.
 */
class TenantUniqueRuleTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(Tenant $tenant): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
    }

    private function seedFournisseur(int $tenantId, string $code): void
    {
        app(TenantContext::class)->runAsTenant(
            $tenantId,
            fn () => Fournisseur::create(['code' => $code, 'raison_sociale' => 'Existant', 'actif' => true])
        );
    }

    public function test_duplicate_code_within_same_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->seedFournisseur($tenant->id, 'DUP');

        $this->actAs($tenant);
        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/fournisseurs', ['code' => 'DUP', 'raison_sociale' => 'Nouveau'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_same_code_is_allowed_in_a_different_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create(['modules' => ['achats']]);
        $this->seedFournisseur($tenantA->id, 'DUP');

        $this->actAs($tenantB);
        $this->withHeader('X-Tenant-Slug', $tenantB->slug)
            ->postJson('/api/fournisseurs', ['code' => 'DUP', 'raison_sociale' => 'Fourn B'])
            ->assertSuccessful();
    }

    public function test_update_checks_uniqueness_within_the_tenant_only(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create(['modules' => ['achats']]);
        $this->seedFournisseur($tenantA->id, 'DUP');
        $this->seedFournisseur($tenantB->id, 'PRIS');
        $fournisseurB = app(TenantContext::class)->runAsTenant(
            $tenantB->id,
            fn () => Fournisseur::create(['code' => 'B-1', 'raison_sociale' => 'Fourn B', 'actif' => true])
        );

        $this->actAs($tenantB);
        $modifier = fn (string $code) => $this->withHeader('X-Tenant-Slug', $tenantB->slug)
            ->putJson("/api/fournisseurs/{$fournisseurB->id}", ['code' => $code, 'raison_sociale' => 'Fourn B']);

        $modifier('B-1')->assertSuccessful();
        $modifier('DUP')->assertSuccessful();
        $modifier('PRIS')->assertStatus(422)->assertJsonValidationErrors(['code']);
    }
}
