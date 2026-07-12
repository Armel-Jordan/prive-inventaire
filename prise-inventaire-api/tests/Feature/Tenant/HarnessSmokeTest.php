<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Valide le socle de test (Étape 0-bis) : migrations, factories, unification de la
 * connexion 'mysql' vers le sqlite de test, et helper d'authentification tenant.
 */
class HarnessSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_and_core_factories_work(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenant)->create();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseHas('admin_users', ['id' => $admin->id, 'tenant_id' => $tenant->id]);
    }

    public function test_hardcoded_mysql_connection_points_to_test_database(): void
    {
        // Les modèles historiques en $connection='mysql' doivent viser la MÊME base que
        // la connexion par défaut de test : sqlite en local (remap), MySQL en CI.
        $this->assertSame(
            DB::connection(config('database.default'))->getDriverName(),
            DB::connection('mysql')->getDriverName(),
        );
    }

    public function test_acting_as_tenant_sets_context(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenant)->create();

        $this->actingAsTenant($admin);

        $this->assertSame((int) $tenant->id, app(TenantContext::class)->getTenantId());
        $this->assertFalse(app(TenantContext::class)->isSuperAdmin());
    }
}
