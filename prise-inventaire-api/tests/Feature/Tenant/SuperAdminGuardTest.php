<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C6 : garde super-admin. Seuls les SuperAdmin peuvent atteindre /super-admin/*.
 */
class SuperAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_cannot_access_super_admin_routes(): void
    {
        $tenant = Tenant::factory()->create();
        // Même avec un token d'abilities larges, un AdminUser n'est pas un super-admin.
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);

        $this->getJson('/api/super-admin/tenants')->assertStatus(403);
        $this->getJson('/api/super-admin/stats')->assertStatus(403);
        $this->putJson("/api/super-admin/tenants/{$tenant->id}/modules", ['modules' => ['ventes']])
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_super_admin_routes(): void
    {
        Sanctum::actingAs(SuperAdmin::factory()->create(), ['*']);

        $this->getJson('/api/super-admin/tenants')->assertOk();
        $this->getJson('/api/super-admin/stats')->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/super-admin/tenants')->assertStatus(401);
    }
}
