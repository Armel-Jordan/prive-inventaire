<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Scopes\TenantScope;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Livraison 2 (API) : /mobile sécurisé (auth requise) + trait tenant sur les 5
 * modèles couplés au mobile.
 */
class MobileTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_routes_require_authentication(): void
    {
        $this->getJson('/api/mobile/employes')->assertStatus(401);
        $this->postJson('/api/mobile/scan/enregistrer', [])->assertStatus(401);
    }

    public function test_tenant_scope_is_applied_to_mobile_models(): void
    {
        $models = [ScanTenant::class, MouvementTenant::class, Secteur::class, ProduitTenant::class, EmployeTenant::class];

        foreach ($models as $model) {
            $this->assertArrayHasKey(
                TenantScope::class,
                (new $model)->getGlobalScopes(),
                "{$model} doit porter le TenantScope."
            );
        }
    }

    public function test_mobile_employes_are_isolated(): void
    {
        $tenantA = Tenant::factory()->create();
        $adminA = AdminUser::factory()->for($tenantA)->create();
        $tenantB = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAsTenant($tenantA->id, fn () => EmployeTenant::create(['numero' => 'E-A1', 'nom' => 'A One', 'actif' => true]));
        $context->runAsTenant($tenantB->id, fn () => EmployeTenant::create(['numero' => 'E-B1', 'nom' => 'B One', 'actif' => true]));

        Sanctum::actingAs($adminA, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenantA->slug)
            ->getJson('/api/mobile/employes')
            ->assertOk()
            ->assertJsonFragment(['numero' => 'E-A1'])
            ->assertJsonMissing(['numero' => 'E-B1']);
    }

    public function test_login_still_resolves_linked_employe(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenant)->create(['email' => 'op@demo.test', 'password' => 'password']);
        app(TenantContext::class)->runAsTenant(
            $tenant->id,
            fn () => EmployeTenant::create(['numero' => 'E1', 'nom' => 'Operator', 'admin_user_id' => $admin->id, 'actif' => true])
        );
        app(TenantContext::class)->reset();

        $this->postJson('/api/auth/login', [
            'tenant_slug' => $tenant->slug,
            'email' => 'op@demo.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.employe_id', fn ($id) => $id !== null);
    }
}
