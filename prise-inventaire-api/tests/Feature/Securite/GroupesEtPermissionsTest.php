<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Groupes et permissions fines (phase 6.2) :
 * - les droits des rôles système ne changent pas sur les routes qui étaient réservées par rôle (comptabilité,
 *   prises d'inventaire, valorisation, catégories de clients, espace client) ;
 * - un groupe personnalisé donne des actions nommées à un compte de rôle « user » (ex. saisie comptable) ;
 * - groupes supplémentaires (droits additionnés), exceptions par compte (le refus l'emporte), groupe désactivé ;
 * - lectures sensibles contrôlées ; catalogue des permissions ; écrans réservés aux administrateurs.
 */
class GroupesEtPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats', 'finance']]);
    }

    private function connecter(string $role, array $attributs = []): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role] + $attributs);
        $this->agir($compte);

        return $compte;
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Statut d'une requête (403 : refusée par les droits ; autre : la requête a passé les droits). */
    private function statut(string $methode, string $uri, array $donnees = []): int
    {
        return $this->json($methode, $uri, $donnees)->status();
    }

    /** Crée un groupe personnalisé (en administrateur) et renvoie son id. */
    private function groupe(string $nom, array $permissions, array $actions = []): int
    {
        $this->connecter('admin');

        return $this->postJson('/api/roles-custom', ['nom' => $nom, 'permissions' => $permissions, 'actions' => $actions])
            ->assertCreated()->json('role_id');
    }

    public function test_droits_des_roles_systeme_inchanges_sur_les_routes_autrefois_reservees(): void
    {
        $this->connecter('user');
        $this->postJson('/api/comptabilite/journaux', ['code' => 'VE', 'libelle' => 'Ventes'])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Saisir des écritures » (Comptabilité).');
        $this->assertSame(403, $this->statut('POST', '/api/prises-inventaire', []));
        $this->assertSame(403, $this->statut('GET', '/api/prises-inventaire/valorisation'));
        $this->assertSame(403, $this->statut('PUT', '/api/portail-clients/parametres', ['actif' => true]));
        $this->assertSame(200, $this->statut('GET', '/api/comptabilite/comptes'));
        $this->assertSame(200, $this->statut('GET', '/api/comptes-clients'));

        $this->connecter('manager');
        $this->assertNotSame(403, $this->statut('POST', '/api/comptabilite/journaux', ['code' => 'VE', 'libelle' => 'Ventes']));
        $this->assertNotSame(403, $this->statut('POST', '/api/prises-inventaire', []));
        $this->assertSame(200, $this->statut('GET', '/api/prises-inventaire/valorisation'));
        $this->assertSame(200, $this->statut('PUT', '/api/portail-clients/parametres', ['actif' => false]));
        // Réservé aux administrateurs, comme avant : clôture d'exercice, catégories de clients.
        $this->assertSame(403, $this->statut('POST', '/api/comptabilite/exercices/999/cloturer'));
        $this->assertSame(403, $this->statut('POST', '/api/categories-clients', ['nom' => 'Grossistes']));

        $this->connecter('admin');
        $this->assertNotSame(403, $this->statut('POST', '/api/comptabilite/exercices/999/cloturer'));
        $this->assertNotSame(403, $this->statut('POST', '/api/categories-clients', ['nom' => 'Grossistes']));

        // Lecture seule : la comptabilité ne s'ouvre plus par l'API sans le droit de la consulter.
        $this->connecter('readonly');
        $this->assertSame(403, $this->statut('GET', '/api/comptabilite/comptes'));
        $this->assertSame(200, $this->statut('GET', '/api/comptes-clients'));
    }

    public function test_un_groupe_comptable_donne_la_saisie_a_un_compte_utilisateur(): void
    {
        $comptable = $this->groupe('Comptable', ['comptabilite' => ['can_view' => true]], ['comptabilite.ecrire' => true, 'comptabilite.etats' => true]);
        $lecteur = $this->groupe('Lecteur comptable', ['comptabilite' => ['can_view' => true]], ['comptabilite.etats' => false]);

        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'role_custom_id' => $comptable]);
        $this->agir($compte);
        $this->assertNotSame(403, $this->statut('POST', '/api/comptabilite/journaux', ['code' => 'VE', 'libelle' => 'Ventes']));
        $this->assertSame(200, $this->statut('GET', '/api/comptabilite/balance'));
        // Ce que le groupe ne donne pas : le plan comptable, la clôture.
        $this->assertSame(403, $this->statut('POST', '/api/comptabilite/comptes', ['numero' => '701', 'libelle' => 'Ventes']));
        $this->assertSame(403, $this->statut('POST', '/api/comptabilite/exercices/999/cloturer'));

        $autre = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'role_custom_id' => $lecteur]);
        $this->agir($autre);
        $this->assertSame(200, $this->statut('GET', '/api/comptabilite/comptes'));
        $this->assertSame(403, $this->statut('GET', '/api/comptabilite/balance'));
        $this->assertSame(403, $this->statut('POST', '/api/comptabilite/journaux', ['code' => 'AC', 'libelle' => 'Achats']));
    }

    public function test_groupes_supplementaires_exceptions_et_groupe_desactive(): void
    {
        $facturation = $this->groupe('Facturation', ['factures' => ['can_view' => true, 'can_create' => true, 'can_edit' => false]], ['factures.emettre' => true]);
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($compte);
        // Rôle système « user » : pas d'émission de facture (droit de modifier les factures absent).
        $this->assertSame(403, $this->statut('POST', '/api/factures/999/emettre'));

        $this->connecter('admin');
        $acces = $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$facturation], 'exceptions' => ['comptabilite.ecrire' => true]])
            ->assertOk()->assertJsonPath('groupes', [$facturation])->json();
        // Venant du groupe, et de l'exception seule (pas des groupes).
        $this->assertSame([true, false, true], [$acces['effectives']['factures.emettre'], $acces['droits_groupes']['comptabilite.ecrire'], $acces['effectives']['comptabilite.ecrire']]);

        $this->agir($compte->fresh());
        $this->assertNotSame(403, $this->statut('POST', '/api/factures/999/emettre'));
        $this->assertNotSame(403, $this->statut('POST', '/api/comptabilite/journaux', ['code' => 'VE', 'libelle' => 'Ventes']));
        $moi = $this->getJson('/api/permissions/me')->assertOk()->json();
        $this->assertTrue($moi['actions']['factures.emettre']);
        $this->assertTrue($moi['permissions']['factures']['can_create']);
        $this->assertFalse($moi['actions']['factures.encaisser']);

        // Le refus l'emporte sur le groupe.
        $this->connecter('admin');
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$facturation], 'exceptions' => ['factures.emettre' => false]])->assertOk();
        $this->agir($compte->fresh());
        $this->assertSame(403, $this->statut('POST', '/api/factures/999/emettre'));

        // Groupe désactivé : ses droits ne comptent plus ; il ne se supprime pas tant qu'un compte l'a.
        $this->connecter('admin');
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$facturation], 'exceptions' => []])->assertOk();
        $this->putJson("/api/roles-custom/{$facturation}", ['actif' => false])->assertOk();
        $this->deleteJson("/api/roles-custom/{$facturation}")->assertStatus(400);
        $this->agir($compte->fresh());
        $this->assertSame(403, $this->statut('POST', '/api/factures/999/emettre'));
    }

    public function test_catalogue_ecran_des_roles_et_reserves_aux_administrateurs(): void
    {
        $this->connecter('user');
        $catalogue = $this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets');
        $ventes = collect($catalogue)->firstWhere('cle', 'ventes');
        $factures = collect($ventes['modules'])->firstWhere('cle', 'factures');
        $emettre = collect($factures['permissions'])->firstWhere('code', 'factures.emettre');
        $this->assertSame(['Émettre une facture', 'Issue an invoice', 'factures.modifier'], [$emettre['fr'], $emettre['en'], $emettre['base']]);
        $this->assertSame(['voir'], array_column(collect(collect($catalogue)->firstWhere('cle', 'ventes')['modules'])->firstWhere('cle', 'comptes_clients')['permissions'], 'action'));

        // Écrans des droits : administrateurs seulement.
        $this->getJson('/api/roles-custom')->assertForbidden();
        $this->connecter('manager');
        $this->getJson('/api/acces-comptes/1')->assertForbidden();

        $this->connecter('admin');
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Vendeur', 'permissions' => ['clients' => ['can_view' => true]], 'actions' => ['devis.envoyer' => true]])
            ->assertCreated()->json('role_id');
        $role = collect($this->getJson('/api/roles-custom')->assertOk()->json())->firstWhere('id', $groupe);
        $this->assertSame([true, ['devis.envoyer' => true], 0], [$role['actif'], $role['actions'], $role['nb_comptes']]);
        // Retour au droit de base (null), permission inconnue refusée.
        $this->putJson("/api/roles-custom/{$groupe}", ['actions' => ['devis.envoyer' => null]])->assertOk();
        $this->assertSame([], (array) collect($this->getJson('/api/roles-custom')->json())->firstWhere('id', $groupe)['actions']);
        $this->putJson("/api/roles-custom/{$groupe}", ['actions' => ['factures.inventee' => true]])->assertUnprocessable();
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['clients.voler' => true]])->assertUnprocessable();
    }
}
