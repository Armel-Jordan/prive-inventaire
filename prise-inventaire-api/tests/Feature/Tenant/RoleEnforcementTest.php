<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C7 : enforcement backend par rôle sur les actions de gestion sensibles
 * (utilisateurs, rôles) — réservées à l'admin de l'entreprise.
 */
class RoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function loginWithRole(string $role): Tenant
    {
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => $role]), ['*']);

        return $tenant;
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $tenant = $this->loginWithRole('user');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->getJson('/api/users')->assertStatus(403);
        $this->withHeader('X-Tenant-Slug', $tenant->slug)->getJson('/api/roles-custom')->assertStatus(403);
    }

    public function test_manager_cannot_manage_roles(): void
    {
        $tenant = $this->loginWithRole('manager');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/roles-custom', ['nom' => 'Custom'])
            ->assertStatus(403);
    }

    public function test_admin_can_access_user_management(): void
    {
        $tenant = $this->loginWithRole('admin');

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->getJson('/api/users')->assertOk();
    }
}
