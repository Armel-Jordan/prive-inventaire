<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Models\TenantTaxe;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration pour tester MANUELLEMENT l'isolation multi-tenant.
 * Crée 2 tenants (alpha, beta), un admin par tenant, et une taxe par tenant.
 *
 * Lancement : php artisan db:seed --class=TenantIsolationDemoSeeder
 */
class TenantIsolationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        // Rôles système partagés (tenant_id NULL) — normalement créés par la migration
        // create_role_permissions_table, mais son createDefaultRoles est court-circuité
        // (deux migrations role_permissions concurrentes, cf. chantier C7). On les seede ici
        // pour que l'UI reconnaisse les admins pendant les tests.
        foreach ([
            ['nom' => 'admin', 'description' => 'Administrateur - Accès complet'],
            ['nom' => 'manager', 'description' => 'Manager - Accès étendu'],
            ['nom' => 'user', 'description' => 'Utilisateur - Accès limité'],
            ['nom' => 'readonly', 'description' => 'Lecture seule'],
        ] as $role) {
            DB::table('roles_custom')->insertOrIgnore(array_merge($role, [
                'tenant_id' => null,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        foreach ([
            ['slug' => 'alpha', 'nom' => 'Entreprise Alpha'],
            ['slug' => 'beta', 'nom' => 'Entreprise Beta'],
        ] as $data) {
            $tenant = Tenant::create([
                'nom' => $data['nom'],
                'slug' => $data['slug'],
                'db_name' => 'tenant_'.$data['slug'],
                'actif' => true,
                'date_expiration' => now()->addYear(),
                'plan' => 'basic',
            ]);

            AdminUser::create([
                'tenant_id' => $tenant->id,
                'nom' => 'Admin '.$data['nom'],
                'email' => 'admin@'.$data['slug'].'.test',
                'password' => 'password',
                'role' => 'admin',
                'actif' => true,
            ]);

            // TenantTaxe porte le trait BelongsToTenant -> création dans le contexte du tenant.
            $context->runAsTenant($tenant->id, function () use ($data) {
                TenantTaxe::create([
                    'nom' => 'TVA '.strtoupper($data['slug']),
                    'taux' => 20,
                    'par_defaut' => true,
                ]);
            });
        }
    }
}
