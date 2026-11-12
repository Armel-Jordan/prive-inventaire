<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Délégation des groupes et des accès (plan de parité fripv2, § 33) : actions « roles.groupes » et « roles.acces »,
 * administrateurs seulement par défaut, et garde-fous contre l'élévation de droits (App\Support\DelegationDroits).
 */
class DelegationDroitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats', 'finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    /** Groupe créé par l'administrateur. */
    private function groupe(string $nom, array $permissions, array $actions = [], bool $actif = true): int
    {
        $this->agir($this->admin);

        return $this->postJson('/api/roles-custom', ['nom' => $nom, 'permissions' => $permissions, 'actions' => $actions, 'actif' => $actif])
            ->assertCreated()->json('role_id');
    }

    /** Compte de rôle « user » ayant ce groupe pour rôle personnalisé. */
    private function compteDuGroupe(int $roleId): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($this->admin);
        $this->postJson("/api/users/{$compte->id}/assign-role", ['role_id' => $roleId])->assertOk();

        return $compte->fresh();
    }

    public function test_par_defaut_administrateurs_seulement(): void
    {
        // Rôles système (sauf l'administrateur) : les deux actions sont refusées par la migration.
        $refusees = DB::table('role_actions')->join('roles_custom', 'roles_custom.id', '=', 'role_actions.role_id')
            ->whereNull('roles_custom.tenant_id')->whereIn('role_actions.permission', ['roles.groupes', 'roles.acces'])
            ->where('role_actions.accorde', false)->pluck('roles_custom.nom')->unique()->sort()->values()->all();
        $this->assertSame(['manager', 'readonly', 'user'], $refusees);

        $manager = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($manager);
        $this->getJson('/api/roles-custom')->assertForbidden();
        $this->postJson('/api/roles-custom', ['nom' => 'Essai', 'permissions' => []])->assertForbidden();
        $this->getJson("/api/acces-comptes/{$vendeur->id}")->assertForbidden();
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => []])->assertForbidden();
        $this->postJson("/api/users/{$vendeur->id}/assign-role", ['role_id' => null])->assertForbidden();

        // Voir sans gérer : la liste et les accès s'affichent, rien ne se modifie.
        $lecteurs = $this->groupe('Lecteurs', ['roles' => ['can_view' => true]], ['roles.groupes' => false, 'roles.acces' => false]);
        $lecteur = $this->compteDuGroupe($lecteurs);
        $this->agir($lecteur);
        $this->getJson('/api/roles-custom')->assertOk();
        $this->getJson("/api/acces-comptes/{$vendeur->id}")->assertOk()->assertJsonPath('modifiable', false);
        $this->postJson('/api/roles-custom', ['nom' => 'Essai', 'permissions' => []])->assertForbidden();
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => []])->assertForbidden();

        // L'administrateur garde tout, ses propres accès compris.
        $this->agir($this->admin);
        $this->getJson("/api/acces-comptes/{$this->admin->id}")->assertOk()->assertJsonPath('modifiable', true);
        $this->putJson("/api/acces-comptes/{$this->admin->id}", ['groupes' => [], 'exceptions' => []])->assertOk();
    }

    public function test_delegation_des_groupes_avec_garde_fous(): void
    {
        $delegues = $this->groupe('Délégués', [
            'roles' => ['can_view' => true],
            'clients' => ['can_view' => true, 'can_create' => true],
        ], ['roles.groupes' => true, 'roles.acces' => true]);
        $comptables = $this->groupe('Comptables', ['comptabilite' => ['can_view' => true]]);
        $inactif = $this->groupe('Comptables en veille', ['comptabilite' => ['can_view' => true]], [], false);
        $chef = $this->compteDuGroupe($delegues);
        $this->agir($chef);

        // Un groupe avec des permissions qu'il a : permis ; avec une permission qu'il n'a pas : refusé, rien n'est créé.
        $vendeurs = $this->postJson('/api/roles-custom', ['nom' => 'Vendeurs', 'permissions' => ['clients' => ['can_view' => true]]])
            ->assertCreated()->json('role_id');
        $this->postJson('/api/roles-custom', ['nom' => 'Pirates', 'permissions' => ['comptabilite' => ['can_view' => true]]])
            ->assertForbidden()->assertJsonPath('permissions', ['comptabilite.etats', 'comptabilite.voir'])
            ->assertJsonPath('message', 'Vous ne pouvez accorder que des permissions que vous avez vous-même : Comptabilité : voir les états comptables, Comptabilité : consulter.');
        $this->assertDatabaseMissing('roles_custom', ['nom' => 'Pirates']);

        // Modifier : ajouter ce qu'il a, retirer ce qu'il n'a pas (le refus l'emporte) ; pas ajouter ce qu'il n'a pas.
        $this->putJson("/api/roles-custom/{$vendeurs}", ['permissions' => ['clients' => ['can_view' => true, 'can_create' => true]]])->assertOk();
        $this->putJson("/api/roles-custom/{$vendeurs}", ['permissions' => ['clients' => ['can_view' => true], 'comptabilite' => ['can_view' => true]]])
            ->assertForbidden();
        $this->assertFalse((bool) DB::table('role_permissions')->where('role_id', $vendeurs)->where('module', 'comptabilite')->value('can_view'));
        $this->putJson("/api/roles-custom/{$comptables}", ['permissions' => []])->assertOk();
        $this->putJson("/api/roles-custom/{$comptables}", ['actif' => false])->assertOk();
        // Réactiver un groupe redonne toutes ses permissions : refusé s'il en donne qu'il n'a pas.
        $this->putJson("/api/roles-custom/{$inactif}", ['actif' => true])->assertForbidden();
        $this->putJson("/api/roles-custom/{$inactif}", ['nom' => 'Comptables (anciens)'])->assertOk();

        // Ses propres groupes : ni modifiés, ni supprimés.
        $this->putJson("/api/roles-custom/{$delegues}", ['nom' => 'Chefs'])->assertForbidden()
            ->assertJsonPath('message', 'Vous ne pouvez pas modifier un groupe dont vous faites partie.');
        $this->deleteJson("/api/roles-custom/{$delegues}")->assertForbidden();

        // Dupliquer : la copie ne doit rien donner qu'il n'a pas.
        $copie = $this->postJson("/api/roles-custom/{$delegues}/dupliquer")->assertCreated()->json('role_id');
        $this->assertSame('Délégués (copie)', DB::table('roles_custom')->where('id', $copie)->value('nom'));
        $this->assertSame('Délégués (copie) 2', DB::table('roles_custom')->where('id', $this->postJson("/api/roles-custom/{$delegues}/dupliquer")->json('role_id'))->value('nom'));
        $admin = DB::table('roles_custom')->whereNull('tenant_id')->where('nom', 'admin')->value('id');
        $this->postJson("/api/roles-custom/{$admin}/dupliquer", ['nom' => 'Tout'])->assertForbidden();
        $this->assertDatabaseMissing('roles_custom', ['nom' => 'Tout']);

        // Membres d'un groupe.
        $membres = $this->getJson("/api/roles-custom/{$delegues}/membres")->assertOk()->json();
        $this->assertSame([[$chef->id, true]], array_map(fn ($m) => [$m['id'], $m['principal']], $membres));
        $this->assertContains($this->admin->id, array_column($this->getJson("/api/roles-custom/{$admin}/membres")->json(), 'id'));

        $this->deleteJson("/api/roles-custom/{$vendeurs}")->assertOk();
    }

    public function test_delegation_des_acces_avec_garde_fous(): void
    {
        $delegues = $this->groupe('Délégués', [
            'roles' => ['can_view' => true],
            'clients' => ['can_view' => true, 'can_create' => true],
        ], ['roles.groupes' => true, 'roles.acces' => true]);
        $vendeurs = $this->groupe('Vendeurs', ['clients' => ['can_view' => true]]);
        $comptables = $this->groupe('Comptables', ['comptabilite' => ['can_view' => true]]);
        $chef = $this->compteDuGroupe($delegues);
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        // Un refus posé par l'administrateur : produits.voir, que le rôle « user » donne et que le chef n'a pas.
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['produits.voir' => false]])->assertOk();

        $this->agir($chef);
        $this->getJson("/api/acces-comptes/{$vendeur->id}")->assertOk()->assertJsonPath('modifiable', true);
        // Un groupe et une exception qu'il a : permis ; un refus, toujours permis.
        $effectives = $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [$vendeurs], 'exceptions' => ['produits.voir' => false, 'clients.creer' => true, 'clients.supprimer' => false]])
            ->assertOk()->json('effectives');
        $this->assertSame([true, true, false], [$effectives['clients.voir'], $effectives['clients.creer'], $effectives['produits.voir']]);
        // Un groupe ou une exception qu'il n'a pas : refusés, rien ne change.
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [$vendeurs, $comptables], 'exceptions' => ['produits.voir' => false]])
            ->assertForbidden()->assertJsonPath('permissions', ['comptabilite.etats', 'comptabilite.voir']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [$vendeurs], 'exceptions' => ['produits.voir' => false, 'factures.emettre' => true]])
            ->assertForbidden();
        // Retirer le refus redonnerait produits.voir, qu'il n'a pas.
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [$vendeurs], 'exceptions' => []])
            ->assertForbidden()->assertJsonPath('permissions', ['produits.voir']);
        $this->assertSame([$vendeurs], DB::table('admin_user_groupes')->where('admin_user_id', $vendeur->id)->pluck('role_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse((bool) DB::table('admin_user_permissions')->where('admin_user_id', $vendeur->id)->where('permission', 'produits.voir')->value('accorde'));

        // Rôle personnalisé : un rôle qu'il couvre, oui ; un rôle plus large, non ; revenir au rôle « user » (produits), non.
        $this->postJson("/api/users/{$vendeur->id}/assign-role", ['role_id' => $vendeurs])->assertOk();
        $this->postJson("/api/users/{$vendeur->id}/assign-role", ['role_id' => $comptables])->assertForbidden();
        $this->postJson("/api/users/{$vendeur->id}/assign-role", ['role_id' => null])->assertForbidden();
        $this->assertSame($vendeurs, (int) $vendeur->fresh()->role_custom_id);

        // Ses propres accès, et ceux d'un administrateur : jamais.
        $this->getJson("/api/acces-comptes/{$chef->id}")->assertOk()->assertJsonPath('modifiable', false);
        $this->putJson("/api/acces-comptes/{$chef->id}", ['groupes' => [], 'exceptions' => []])->assertForbidden()
            ->assertJsonPath('message', 'Vous ne pouvez pas modifier vos propres accès.');
        $this->postJson("/api/users/{$chef->id}/assign-role", ['role_id' => null])->assertForbidden();
        $this->putJson("/api/acces-comptes/{$this->admin->id}", ['groupes' => [], 'exceptions' => ['clients.voir' => false]])->assertForbidden();
        $this->postJson("/api/users/{$this->admin->id}/assign-role", ['role_id' => $vendeurs])->assertForbidden();
    }

    public function test_isolation_entre_entreprises(): void
    {
        $delegues = $this->groupe('Délégués', ['roles' => ['can_view' => true]], ['roles.groupes' => true, 'roles.acces' => true]);
        $chef = $this->compteDuGroupe($delegues);

        $autre = Tenant::factory()->create();
        $adminAutre = AdminUser::factory()->for($autre)->create(['role' => 'admin']);
        $compteAutre = AdminUser::factory()->for($autre)->create(['role' => 'user']);
        $this->agir($adminAutre, $autre);
        $groupeAutre = $this->postJson('/api/roles-custom', ['nom' => 'Ailleurs', 'permissions' => []])->assertCreated()->json('role_id');

        $this->agir($chef);
        $this->getJson("/api/roles-custom/{$groupeAutre}/membres")->assertNotFound();
        $this->postJson("/api/roles-custom/{$groupeAutre}/dupliquer")->assertNotFound();
        $this->putJson("/api/roles-custom/{$groupeAutre}", ['nom' => 'Pris'])->assertNotFound();
        $this->getJson("/api/acces-comptes/{$compteAutre->id}")->assertNotFound();
        $this->putJson("/api/acces-comptes/{$compteAutre->id}", ['groupes' => [], 'exceptions' => []])->assertNotFound();
        $this->postJson("/api/users/{$compteAutre->id}/assign-role", ['role_id' => null])->assertNotFound();
        $this->assertNotContains('Ailleurs', collect($this->getJson('/api/roles-custom')->json())->pluck('nom'));
    }
}
