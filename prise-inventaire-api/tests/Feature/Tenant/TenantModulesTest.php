<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modules fonctionnels par entreprise (achats/ventes/finance) : modèle, gating
 * des routes, exposition au front, gestion super-admin.
 */
class TenantModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_modules_are_always_active(): void
    {
        $tenant = Tenant::factory()->create(['modules' => []]);

        $this->assertTrue($tenant->hasModule('inventaire'));
        $this->assertTrue($tenant->hasModule('parametres'));
        $this->assertFalse($tenant->hasModule('ventes'));
    }

    public function test_optional_modules_depend_on_subscription(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);

        $this->assertTrue($tenant->hasModule('ventes'));
        $this->assertFalse($tenant->hasModule('achats'));
        $this->assertEqualsCanonicalizing(
            ['inventaire', 'parametres', 'ventes'],
            $tenant->activeModules()
        );
    }

    public function test_default_modules_for_plan(): void
    {
        $this->assertSame([], Tenant::defaultModulesForPlan('starter'));
        $this->assertEqualsCanonicalizing(['achats', 'ventes'], Tenant::defaultModulesForPlan('pro'));
        $this->assertEqualsCanonicalizing(['achats', 'ventes', 'finance'], Tenant::defaultModulesForPlan('enterprise'));
    }

    public function test_route_is_blocked_when_module_disabled(): void
    {
        $tenant = Tenant::factory()->create(['modules' => []]); // pas de ventes
        $admin = AdminUser::factory()->for($tenant)->create();

        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/clients')
            ->assertStatus(403)
            ->assertJson(['module' => 'ventes']);
    }

    public function test_route_is_allowed_when_module_enabled(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $admin = AdminUser::factory()->for($tenant)->create();

        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/clients')
            ->assertOk();
    }

    public function test_auth_me_exposes_active_modules(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $admin = AdminUser::factory()->for($tenant)->create();

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.modules', fn ($m) => in_array('ventes', $m) && in_array('inventaire', $m) && ! in_array('achats', $m));
    }

    public function test_super_admin_can_update_tenant_modules(): void
    {
        $tenant = Tenant::factory()->create(['modules' => []]);
        $superAdmin = SuperAdmin::factory()->create();

        Sanctum::actingAs($superAdmin, ['*']);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/modules", ['modules' => ['achats', 'ventes']])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['achats', 'ventes'], $tenant->fresh()->modules);
    }

    public function test_super_admin_module_update_rejects_unknown_module(): void
    {
        $tenant = Tenant::factory()->create(['modules' => []]);
        $superAdmin = SuperAdmin::factory()->create();

        Sanctum::actingAs($superAdmin, ['*']);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/modules", ['modules' => ['inventaire']])
            ->assertStatus(422); // inventaire est un module coeur, pas optionnel
    }
}
