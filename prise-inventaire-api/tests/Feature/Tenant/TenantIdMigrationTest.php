<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Valide l'Étape 3 : ajout de tenant_id aux tables manquantes + backfill conditionnel.
 */
class TenantIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_id_columns_exist(): void
    {
        $tables = ['produits', 'employes', 'secteurs', 'notifications', 'mouvements_inventaire', 'roles_custom'];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "La table {$table} doit posséder la colonne tenant_id."
            );
        }
    }

    public function test_produits_composite_index_is_created(): void
    {
        $names = collect(Schema::getIndexes('produits'))->pluck('name');

        $this->assertTrue($names->contains('produits_tenant_deleted_at_index'));
    }

    public function test_backfill_assigns_custom_roles_and_keeps_system_roles_shared(): void
    {
        $tenant = Tenant::factory()->create();

        DB::table('roles_custom')->insert([
            ['nom' => 'sys_role', 'is_system' => true, 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['nom' => 'custom_x', 'is_system' => false, 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runBackfill();

        // Le rôle custom (non-système) est rattaché à l'unique tenant.
        $this->assertDatabaseHas('roles_custom', ['nom' => 'custom_x', 'tenant_id' => $tenant->id]);
        // Le rôle système reste partagé (tenant_id NULL).
        $this->assertDatabaseHas('roles_custom', ['nom' => 'sys_role', 'tenant_id' => null]);
    }

    public function test_backfill_is_noop_when_multiple_tenants_exist(): void
    {
        Tenant::factory()->count(2)->create();

        DB::table('roles_custom')->insert([
            'nom' => 'custom_y',
            'is_system' => false,
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        // Ambiguïté multi-tenant : la ligne reste NULL (rattachement manuel requis).
        $this->assertDatabaseHas('roles_custom', ['nom' => 'custom_y', 'tenant_id' => null]);
    }

    private function runBackfill(): void
    {
        (require database_path('migrations/2026_07_10_100003_backfill_tenant_id_single_tenant.php'))->up();
    }
}
