<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C4 : les stats du dashboard legacy (requêtes DB::table brutes) sont scopées au tenant.
 */
class DashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_stats_are_scoped_to_current_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $adminA = AdminUser::factory()->for($tenantA)->create();
        $tenantB = Tenant::factory()->create();

        DB::table('secteurs')->insert([
            ['code' => 'A1', 'nom' => 'Secteur A1', 'tenant_id' => $tenantA->id, 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'A2', 'nom' => 'Secteur A2', 'tenant_id' => $tenantA->id, 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'B1', 'nom' => 'Secteur B1', 'tenant_id' => $tenantB->id, 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenantA->slug)
            ->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('inventaire.secteurs', 2); // seulement les secteurs de A, pas les 3
    }
}
