<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\SuperAdmin;
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

        // Super-admin global (gère tous les tenants). Login : POST /api/super-admin/login.
        SuperAdmin::firstOrCreate(
            ['email' => 'super@prise.test'],
            ['nom' => 'Super Admin', 'password' => 'password', 'actif' => true]
        );

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
            // alpha : plan enterprise -> tous les modules (dont Finance).
            ['slug' => 'alpha', 'nom' => 'Entreprise Alpha', 'plan' => 'enterprise'],
            // beta : plan pro -> Achats + Ventes, PAS Finance (démontre le gating).
            ['slug' => 'beta', 'nom' => 'Entreprise Beta', 'plan' => 'pro'],
        ] as $data) {
            $tenant = Tenant::create([
                'nom' => $data['nom'],
                'slug' => $data['slug'],
                'db_name' => 'tenant_'.$data['slug'],
                'actif' => true,
                'date_expiration' => now()->addYear(),
                'plan' => $data['plan'],
                'modules' => Tenant::defaultModulesForPlan($data['plan']),
            ]);

            AdminUser::create([
                'tenant_id' => $tenant->id,
                'nom' => 'Admin '.$data['nom'],
                'email' => 'admin@'.$data['slug'].'.test',
                'password' => 'password',
                'role' => 'admin',
                'actif' => true,
            ]);

            // Modèles porteurs du trait BelongsToTenant -> création dans le contexte du tenant
            // (tenant_id injecté automatiquement). Données distinctes par tenant pour visualiser
            // l'isolation dans l'UI (Taxes, Fournisseurs, Clients).
            $context->runAsTenant($tenant->id, function () use ($data) {
                $slug = strtoupper($data['slug']);

                TenantTaxe::create(['nom' => 'TVA '.$slug, 'taux' => 20, 'par_defaut' => true]);

                foreach (range(1, 2) as $i) {
                    Fournisseur::create([
                        'code' => 'FRN-'.$slug.'-'.$i,
                        'raison_sociale' => 'Fournisseur '.$slug.' '.$i,
                        'actif' => true,
                    ]);
                }

                foreach (range(1, 3) as $i) {
                    Client::create([
                        'code' => 'CLI-'.$slug.'-'.$i,
                        'raison_sociale' => 'Client '.$slug.' '.$i,
                        'adresse_facturation' => $i.' rue de '.$data['nom'],
                        'ville' => 'Ville '.$slug,
                        'code_postal' => str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    ]);
                }
            });
        }
    }
}
