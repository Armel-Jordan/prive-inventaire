<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\ProduitTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Droits par module appliqués par l'API (middleware « droits ») et accès des employés :
 * - les écritures suivent la matrice du rôle : un « user » ne modifie ni les paramètres, ni les taxes, ni les
 *   produits ; un manager crée et modifie sans supprimer ; un administrateur peut tout ;
 * - un rôle personnalisé de l'entreprise s'attribue à un compte et remplace les droits de son rôle système ;
 * - désactiver un employé coupe son accès (connexion et sessions ouvertes) ; un courriel est exigé pour donner
 *   l'accès à l'application.
 */
class DroitsParModuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
    }

    private function connecter(string $role = 'admin', array $attributs = []): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role] + $attributs);
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function secteur(): int
    {
        return $this->chezLeTenant(fn () => Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id);
    }

    public function test_un_utilisateur_ne_modifie_ni_parametres_ni_taxes_ni_produits(): void
    {
        $secteurId = $this->secteur();
        $this->connecter('user');

        $this->postJson('/api/produits', ['numero' => 'P-1', 'description' => 'Ciment', 'secteur_id' => $secteurId])
            ->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de créer dans « Produits ».');
        $this->putJson('/api/parametres', ['nom_entreprise' => 'Piratée'])->assertForbidden();
        $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 5])->assertForbidden();
        $this->putJson('/api/approbations/settings', ['seuil_transfert' => 1])->assertForbidden()
            ->assertJsonPath('module', 'configuration');
        $this->postJson('/api/secteurs', ['code' => 'B1', 'nom' => 'Allée 2'])->assertForbidden();

        // Ce que son rôle permet : saisir un scan ; les lectures restent ouvertes.
        $this->postJson('/api/scan/enregistrer', ['numero' => 'P-1', 'quantite' => 3, 'employe' => 'E1', 'secteur' => 'A1'])
            ->assertSuccessful();
        $this->getJson('/api/produits')->assertOk();
    }

    public function test_un_manager_cree_et_modifie_sans_supprimer_ni_configurer(): void
    {
        $secteurId = $this->secteur();
        $this->connecter('manager');

        $id = $this->postJson('/api/produits', ['numero' => 'P-1', 'description' => 'Ciment', 'secteur_id' => $secteurId])
            ->assertCreated()->json('produit.id') ?? $this->chezLeTenant(fn () => ProduitTenant::where('numero', 'P-1')->value('id'));
        $this->putJson("/api/produits/{$id}", ['description' => 'Ciment gris', 'secteur_id' => $secteurId])->assertOk();
        $this->deleteJson("/api/produits/{$id}")->assertForbidden();
        $this->putJson('/api/parametres', ['nom_entreprise' => 'Autre'])->assertForbidden();
    }

    public function test_un_administrateur_peut_tout_et_les_calculs_restent_libres(): void
    {
        $this->connecter('admin');
        $this->putJson('/api/parametres', ['nom_entreprise' => 'Entreprise Alpha'])->assertOk();

        $this->connecter('readonly');
        $this->postJson('/api/scan/enregistrer', ['numero' => 'P-1', 'quantite' => 3, 'employe' => 'E1', 'secteur' => 'A1'])
            ->assertForbidden();
        $this->postJson('/api/prix/calculer', ['produit_id' => 999999, 'quantite' => 1])->assertStatus(422);
    }

    public function test_un_role_personnalise_attribue_remplace_les_droits_du_role_systeme(): void
    {
        $secteurId = $this->secteur();
        $magasinier = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);

        $this->connecter('admin');
        $roleId = $this->postJson('/api/roles-custom', [
            'nom' => 'Magasinier',
            'permissions' => ['produits' => ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false]],
        ])->assertCreated()->json('role_id');
        $this->postJson("/api/users/{$magasinier->id}/assign-role", ['role_id' => $roleId])->assertOk();
        $this->assertSame($roleId, (int) $magasinier->fresh()->role_custom_id);

        Sanctum::actingAs($magasinier->fresh(), ['*']);
        $this->getJson('/api/permissions/me')->assertOk()
            ->assertJsonPath('role', 'Magasinier')
            ->assertJsonPath('permissions.produits.can_create', true);
        $this->postJson('/api/produits', ['numero' => 'P-1', 'description' => 'Ciment', 'secteur_id' => $secteurId])->assertCreated();

        // Un rôle encore attribué ne se supprime pas.
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->deleteJson("/api/roles-custom/{$roleId}")->assertStatus(400);
    }

    public function test_un_compte_desactive_est_refuse_meme_avec_un_jeton(): void
    {
        $this->connecter('admin', ['actif' => false]);

        $this->getJson('/api/produits')->assertUnauthorized()->assertJsonPath('message', 'Ce compte est désactivé.');
    }

    public function test_desactiver_un_employe_coupe_son_acces_et_le_reactiver_le_rend(): void
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'email' => 'awa@alpha.test', 'password' => 'secret123']);
        $compte->createToken('web');
        $employeId = $this->chezLeTenant(fn () => EmployeTenant::create([
            'tenant_id' => $this->tenant->id, 'admin_user_id' => $compte->id, 'numero' => 'E-1', 'nom' => 'Ndiaye', 'email' => $compte->email,
        ])->id);
        $this->connecter('admin');

        $this->deleteJson("/api/employes/{$employeId}")->assertOk();

        $this->assertFalse((bool) $compte->fresh()->actif);
        $this->assertSame(0, $compte->tokens()->count());
        $this->assertSame([$employeId], collect($this->getJson('/api/employes?actif=0')->json())->pluck('id')->all());
        $this->assertSame([], collect($this->getJson('/api/employes?actif=1')->json())->pluck('id')->all());
        $this->postJson('/api/auth/login', ['email' => 'awa@alpha.test', 'password' => 'secret123', 'tenant_slug' => $this->tenant->slug])
            ->assertUnauthorized();

        $this->putJson("/api/employes/{$employeId}", ['actif' => true])->assertOk()->assertJsonPath('employe.admin_user.actif', true);
        $this->postJson('/api/auth/login', ['email' => 'awa@alpha.test', 'password' => 'secret123', 'tenant_slug' => $this->tenant->slug])
            ->assertOk();
    }

    public function test_le_dernier_administrateur_ne_se_desactive_pas(): void
    {
        $admin = $this->connecter('admin');
        $employeId = $this->chezLeTenant(fn () => EmployeTenant::create([
            'tenant_id' => $this->tenant->id, 'admin_user_id' => $admin->id, 'numero' => 'E-1', 'nom' => 'Admin', 'email' => $admin->email,
        ])->id);

        $this->deleteJson("/api/employes/{$employeId}")->assertUnprocessable();
        $this->assertTrue((bool) $admin->fresh()->actif);
    }

    public function test_un_acces_sans_courriel_est_refuse_proprement(): void
    {
        $this->connecter('admin');

        $this->postJson('/api/employes', ['numero' => 'E-1', 'nom' => 'Sans courriel', 'role' => 'user', 'password' => 'secret123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Un courriel est nécessaire pour donner l\'accès à l\'application.']);

        $employeId = $this->postJson('/api/employes', ['numero' => 'E-2', 'nom' => 'Sans accès'])->assertCreated()->json('employe.id');
        $this->putJson("/api/employes/{$employeId}", ['role' => 'user', 'password' => 'secret123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_seul_un_administrateur_attribue_un_role_personnalise_ou_lecture_seule_aux_comptes_d_un_manager(): void
    {
        $this->connecter('admin');
        $roleId = $this->postJson('/api/roles-custom', ['nom' => 'Comptable', 'permissions' => []])->assertCreated()->json('role_id');

        $this->connecter('manager');
        $this->postJson('/api/employes', [
            'numero' => 'E-1', 'nom' => 'Lecteur', 'email' => 'lecteur@alpha.test', 'role' => 'readonly', 'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('employe.admin_user.role', 'readonly');
        $this->postJson('/api/employes', [
            'numero' => 'E-2', 'nom' => 'Comptable', 'email' => 'compta@alpha.test', 'role' => 'user', 'password' => 'secret123',
            'role_custom_id' => $roleId,
        ])->assertForbidden();
    }
}
