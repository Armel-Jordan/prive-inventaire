<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Valide le middleware ResolveTenantContext (Étape 2) : alimentation du contexte
 * depuis le token authentifié et rejet 403 en cas de slug non concordant.
 */
class ResolveTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_token_sets_its_tenant_context(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenant)->create();

        Route::middleware(['auth:sanctum', 'tenant', 'tenant.context'])
            ->get('/_test/echo', fn () => response()->json([
                'tenant_id' => app(TenantContext::class)->getTenantId(),
                'super' => app(TenantContext::class)->isSuperAdmin(),
            ]));

        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/_test/echo')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id, 'super' => false]);
    }

    public function test_cross_slug_is_rejected_with_403(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenantA)->create();

        Route::middleware(['auth:sanctum', 'tenant', 'tenant.context'])
            ->get('/_test/echo', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($admin, ['*']);

        // L'admin du tenant A tente d'agir avec le slug du tenant B.
        $this->withHeader('X-Tenant-Slug', $tenantB->slug)
            ->getJson('/_test/echo')
            ->assertStatus(403);
    }

    public function test_super_admin_token_marks_super_admin(): void
    {
        $superAdmin = SuperAdmin::factory()->create();

        Route::middleware(['auth:sanctum', 'tenant.context'])
            ->get('/_test/echo-super', fn () => response()->json([
                'super' => app(TenantContext::class)->isSuperAdmin(),
            ]));

        Sanctum::actingAs($superAdmin, ['*']);

        $this->getJson('/_test/echo-super')
            ->assertOk()
            ->assertJson(['super' => true]);
    }
}
