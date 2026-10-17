<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rôles et accès (chantier C7) :
 * - les rôles personnalisés sont propres à chaque entreprise ; les rôles système, communs à toutes,
 *   ne se modifient pas depuis une entreprise ;
 * - les permissions par module existent pour les rôles système (menu d'un manager ou d'un utilisateur) ;
 * - seuls un administrateur ou un manager gèrent les employés ; seul un administrateur attribue les
 *   rôles admin et manager ; le dernier administrateur actif garde son accès.
 */
class RolesEtAccesTest extends TestCase
{
    use RefreshDatabase;

    private function connecter(Tenant $tenant, string $role = 'admin'): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create(['role' => $role]);
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function roleSysteme(string $nom): int
    {
        return (int) DB::table('roles_custom')->whereNull('tenant_id')->where('is_system', true)->where('nom', $nom)->value('id');
    }

    private function employe(Tenant $tenant, ?AdminUser $compte, string $numero): int
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, fn () => EmployeTenant::create([
            'tenant_id' => $tenant->id, 'admin_user_id' => $compte?->id, 'numero' => $numero,
            'nom' => $compte?->nom ?? 'Employé', 'email' => $compte?->email,
        ])->id);
    }

    public function test_les_roles_personnalises_sont_propres_a_chaque_entreprise(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $this->connecter($alpha);
        $roleAlpha = $this->postJson('/api/roles-custom', [
            'nom' => 'Magasinier', 'permissions' => ['produits' => ['can_view' => true]],
        ])->assertCreated()->json('role_id');

        $this->connecter($beta);
        $noms = collect($this->getJson('/api/roles-custom')->assertOk()->json())->pluck('nom');
        $this->assertEqualsCanonicalizing(['admin', 'manager', 'user', 'readonly'], $noms->all());

        // Même nom permis dans une autre entreprise ; le rôle d'alpha reste introuvable pour beta.
        $this->postJson('/api/roles-custom', ['nom' => 'Magasinier', 'permissions' => []])->assertCreated();
        $this->getJson("/api/roles-custom/{$roleAlpha}")->assertNotFound();
        $this->putJson("/api/roles-custom/{$roleAlpha}", ['nom' => 'Pirate'])->assertNotFound();
        $this->deleteJson("/api/roles-custom/{$roleAlpha}")->assertNotFound();
        $this->assertSame('Magasinier', DB::table('roles_custom')->where('id', $roleAlpha)->value('nom'));
    }

    public function test_les_roles_systeme_ne_se_modifient_pas_depuis_une_entreprise(): void
    {
        $this->connecter(Tenant::factory()->create());
        $user = $this->roleSysteme('user');

        $this->putJson("/api/roles-custom/{$user}", ['permissions' => ['configuration' => ['can_view' => true]]])
            ->assertForbidden();
        $this->deleteJson("/api/roles-custom/{$user}")->assertForbidden();

        $this->assertFalse((bool) DB::table('role_permissions')->where('role_id', $user)->where('module', 'configuration')->value('can_view'));
    }

    public function test_nom_unique_dans_l_entreprise_et_noms_systeme_reserves(): void
    {
        $this->connecter(Tenant::factory()->create());

        $id = $this->postJson('/api/roles-custom', ['nom' => 'Magasinier', 'permissions' => []])->assertCreated()->json('role_id');
        $this->postJson('/api/roles-custom', ['nom' => 'Magasinier', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->postJson('/api/roles-custom', ['nom' => 'Manager', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('nom');

        // Son propre rôle se renomme et se supprime.
        $this->putJson("/api/roles-custom/{$id}", ['nom' => 'Chef magasinier'])->assertOk();
        $this->deleteJson("/api/roles-custom/{$id}")->assertOk();
        $this->assertDatabaseMissing('roles_custom', ['id' => $id]);
    }

    public function test_les_anciens_roles_sans_entreprise_sont_masques(): void
    {
        DB::table('roles_custom')->insert(['nom' => 'Ancien rôle', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->connecter(Tenant::factory()->create());

        $this->assertNotContains('Ancien rôle', collect($this->getJson('/api/roles-custom')->assertOk()->json())->pluck('nom'));
        $this->postJson('/api/roles-custom', ['nom' => 'Ancien rôle', 'permissions' => []])->assertCreated();
    }

    public function test_la_migration_se_rejoue_et_recree_un_role_systeme_manquant(): void
    {
        // Base où le rôle système « manager » manque, et où un ancien rôle personnalisé sans entreprise
        // porte son nom : la migration recrée le rôle système sans toucher à l'ancien.
        $manager = $this->roleSysteme('manager');
        DB::table('role_permissions')->where('role_id', $manager)->delete();
        DB::table('roles_custom')->where('id', $manager)->delete();
        $ancien = DB::table('roles_custom')->insertGetId(['nom' => 'manager', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        $lignes = DB::table('role_permissions')->count();

        $migration = require database_path('migrations/2026_10_16_100019_isoler_roles_par_entreprise.php');
        $migration->up();
        $migration->up();
        // Les modules et actions ajoutés ensuite (groupes et permissions fines, notes de crédit, acomptes, relances,
        // retours clients et fournisseurs) se rejouent aussi.
        foreach (['100029_creer_groupes_et_permissions_nommees', '100030_creer_notes_de_credit', '100031_creer_acomptes', '100033_creer_relances_paiement', '100035_creer_retours_clients', '100036_creer_retours_fournisseurs'] as $suivante) {
            (require database_path("migrations/2026_10_16_{$suivante}.php"))->up();
        }

        $nouveau = $this->roleSysteme('manager');
        $this->assertNotSame($ancien, $nouveau);
        $this->assertSame(4, DB::table('roles_custom')->whereNull('tenant_id')->where('is_system', true)->count());
        $parRole = DB::table('role_permissions')->where('role_id', $this->roleSysteme('admin'))->count();
        $this->assertSame($lignes + $parRole, DB::table('role_permissions')->count());
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $ancien)->count());
    }

    public function test_permissions_par_module_des_roles_systeme(): void
    {
        $tenant = Tenant::factory()->create();

        $this->connecter($tenant, 'manager');
        $this->getJson('/api/permissions/me')->assertOk()
            ->assertJsonPath('role', 'manager')
            ->assertJsonPath('permissions.produits.can_view', true)
            ->assertJsonPath('permissions.produits.can_delete', false)
            ->assertJsonPath('permissions.factures.can_view', true)
            ->assertJsonPath('permissions.configuration.can_view', false)
            ->assertJsonPath('permissions.roles.can_view', false);

        $this->connecter($tenant, 'user');
        $this->getJson('/api/permissions/me')->assertOk()
            ->assertJsonPath('role', 'user')
            ->assertJsonPath('permissions.inventaires.can_view', true)
            ->assertJsonPath('permissions.inventaires.can_create', true)
            ->assertJsonPath('permissions.employes.can_view', false);

        // Anciennes permissions « rôle + permission » toujours servies par /roles/my-permissions.
        $this->assertContains('scans.read', $this->getJson('/api/roles/my-permissions')->assertOk()->json('permissions'));
    }

    public function test_un_utilisateur_ne_gere_ni_les_employes_ni_les_acces(): void
    {
        $tenant = Tenant::factory()->create();
        $moi = $this->connecter($tenant, 'user');
        $monEmploye = $this->employe($tenant, $moi, 'E-1');

        $this->postJson('/api/employes', ['numero' => 'E-2', 'nom' => 'Pirate', 'email' => 'pirate@test.cm', 'role' => 'admin', 'password' => 'secret12'])
            ->assertForbidden();
        $this->putJson("/api/employes/{$monEmploye}", ['role' => 'admin'])->assertForbidden();

        $this->assertSame('user', $moi->fresh()->role);
        $this->getJson('/api/employes')->assertOk();
    }

    public function test_un_manager_n_attribue_que_le_role_utilisateur(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = AdminUser::factory()->for($tenant)->create(['role' => 'admin', 'email' => 'admin@test.cm']);
        $employeAdmin = $this->employe($tenant, $admin, 'E-1');
        $this->connecter($tenant, 'manager');

        $this->postJson('/api/employes', ['numero' => 'E-2', 'nom' => 'Ali', 'email' => 'ali@test.cm', 'role' => 'user', 'password' => 'secret12'])
            ->assertCreated()->assertJsonPath('employe.admin_user.role', 'user');
        $this->postJson('/api/employes', ['numero' => 'E-3', 'nom' => 'Awa', 'email' => 'awa@test.cm', 'role' => 'admin', 'password' => 'secret12'])
            ->assertForbidden();

        // Fiche d'un administrateur : ses informations oui, son accès non (rôle, mot de passe, adresse).
        $this->putJson("/api/employes/{$employeAdmin}", ['nom' => $admin->nom, 'prenom' => 'Paul', 'email' => 'admin@test.cm', 'role' => 'admin'])
            ->assertOk();
        $this->putJson("/api/employes/{$employeAdmin}", ['nom' => $admin->nom, 'email' => 'moi@test.cm', 'role' => 'admin'])->assertForbidden();
        $this->putJson("/api/employes/{$employeAdmin}", ['nom' => $admin->nom, 'role' => 'admin', 'password' => 'secret12'])->assertForbidden();
        $this->putJson("/api/employes/{$employeAdmin}", ['role' => 'remove'])->assertForbidden();

        $admin->refresh();
        $this->assertSame(['admin', 'admin@test.cm'], [$admin->role, $admin->email]);
    }

    public function test_le_dernier_administrateur_garde_son_acces(): void
    {
        $tenant = Tenant::factory()->create();
        $moi = $this->connecter($tenant);
        $monEmploye = $this->employe($tenant, $moi, 'E-1');

        $this->putJson("/api/employes/{$monEmploye}", ['role' => 'remove'])->assertUnprocessable();
        $this->putJson("/api/employes/{$monEmploye}", ['nom' => $moi->nom, 'role' => 'manager'])->assertUnprocessable();
        $this->putJson("/api/users/{$moi->id}", ['role' => 'manager'])->assertUnprocessable();
        $this->putJson("/api/users/{$moi->id}", ['actif' => false])->assertUnprocessable();
        $this->assertSame('admin', $moi->fresh()->role);

        // Avec un second administrateur, le premier peut changer de rôle.
        AdminUser::factory()->for($tenant)->create(['role' => 'admin']);
        $this->putJson("/api/users/{$moi->id}", ['role' => 'manager'])->assertOk();
    }
}
