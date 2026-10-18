<?php

namespace Tests\Feature\Prix;

use App\Models\AdminUser;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\Notification;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\Planificateur;
use App\Support\TenantContext;
use App\Taches\AppliquerRevisionsPrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Révisions de prix planifiées (plan de parité fripv2, phase 1.2d) : ajustement en pourcentage ou en montant sur le prix
 * de base et les prix saisis des listes (arrondi, jamais sous zéro), retouches, planification à une date d'entrée en
 * vigueur appliquée par la tâche quotidienne, application immédiate, une seule application, droits et isolation.
 */
class RevisionsPrixTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, ProduitTenant> */
    private array $produits;

    private ListePrix $gros;

    private ListePrix $export;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00', 'UTC'));
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Admin']);
        $this->agir($this->admin);

        $this->chez(function () {
            foreach ([
                ['CIM-25', 'Ciment 25 kg', 'Liants', 33.33, true],
                ['CIM-50', 'Ciment 50 kg', 'Liants', 100, true],
                ['CIM-SP', 'Ciment spécial', 'Liants', null, true],
                ['CIM-OLD', 'Ancien ciment', 'Liants', 80, false],
                ['FER-10', 'Fer à béton 10', 'Aciers', 50, true],
            ] as [$numero, $description, $categorie, $prix, $actif]) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'categorie' => $categorie, 'prix_unitaire' => $prix, 'actif' => $actif]);
            }
            $this->gros = ListePrix::create(['nom' => 'Gros', 'ajustement_pourcentage' => -10, 'precision' => 2, 'actif' => true]);
            $this->export = ListePrix::create(['nom' => 'Export', 'ajustement_pourcentage' => 0, 'precision' => 0, 'actif' => true]);
            $archive = ListePrix::create(['nom' => 'Archive', 'ajustement_pourcentage' => 0, 'precision' => 2, 'actif' => false]);
            ListePrixProduit::create(['liste_prix_id' => $this->export->id, 'produit_id' => $this->produits['CIM-50']->id, 'prix' => 123]);
            ListePrixProduit::create(['liste_prix_id' => $this->export->id, 'produit_id' => $this->produits['FER-10']->id, 'prix' => 61]);
            ListePrixProduit::create(['liste_prix_id' => $archive->id, 'produit_id' => $this->produits['CIM-50']->id, 'prix' => 99]);
        });
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function id(string $numero): int
    {
        return $this->produits[$numero]->id;
    }

    /** Prix en place : prix de base, ou prix saisi dans une liste (null s'il n'y en a pas). */
    private function prix(string $numero, ?ListePrix $liste = null): ?float
    {
        return $this->chez(fn () => $liste === null
            ? (float) ProduitTenant::withTrashed()->findOrFail($this->id($numero))->prix_unitaire
            : ListePrixProduit::where('liste_prix_id', $liste->id)->where('produit_id', $this->id($numero))->value('prix'));
    }

    /** Lignes résumées : [numéro, liste, prix actuel, nouveau prix] (le JSON écrit 35.0 « 35 »). */
    private function resume(array $lignes): array
    {
        return array_map(fn (array $l) => [
            $l['numero'], $l['liste'], $l['prix_actuel'] === null ? null : (float) $l['prix_actuel'], (float) $l['nouveau_prix'],
        ], $lignes);
    }

    private function revision(array $donnees = []): array
    {
        return $this->postJson('/api/revisions-prix', $donnees + [
            'nom' => 'Hausse des liants', 'categorie' => 'Liants', 'cible' => 'base', 'type_ajustement' => 'pourcentage', 'valeur_ajustement' => 5,
            'lignes' => [
                ['produit_id' => $this->id('CIM-25'), 'liste_prix_id' => null, 'nouveau_prix' => 35],
                ['produit_id' => $this->id('CIM-50'), 'liste_prix_id' => null, 'nouveau_prix' => 104.5],
            ],
        ])->assertCreated()->json();
    }

    private function executerTache(): string
    {
        return $this->chez(fn () => app(AppliquerRevisionsPrix::class)->executer());
    }

    public function test_l_ajustement_propose_les_nouveaux_prix_sans_rien_changer(): void
    {
        // Prix de base des liants actifs, + 5 % : arrondi à 2 décimales ; sans prix ou inactif, le produit n'y est pas.
        $base = $this->postJson('/api/revisions-prix/apercu', ['categorie' => 'Liants', 'cible' => 'base', 'type' => 'pourcentage', 'valeur' => 5])
            ->assertOk()->json();
        $this->assertSame(2, $base['total']);
        $this->assertSame([['CIM-25', null, 33.33, 35.0], ['CIM-50', null, 100.0, 105.0]], $this->resume($base['lignes']));
        // Listes actives dont le prix suit la formule (il changera avec le prix de base) : pas celles où un prix est saisi.
        $this->assertSame([[$this->export->id, $this->gros->id], [$this->gros->id]], array_column($base['lignes'], 'formules'));

        // Prix saisis d'une liste, - 200 : jamais sous zéro.
        $liste = $this->postJson('/api/revisions-prix/apercu', ['cible' => 'liste', 'liste_prix_id' => $this->export->id, 'type' => 'montant', 'valeur' => -200])
            ->assertOk()->json('lignes');
        $this->assertSame([['CIM-50', 'Export', 123.0, 0.0], ['FER-10', 'Export', 61.0, 0.0]], $this->resume($liste));

        // Toutes les listes actives, + 10 %, produits inactifs compris : arrondi à la précision de chaque liste.
        $toutes = $this->postJson('/api/revisions-prix/apercu', ['cible' => 'toutes', 'type' => 'pourcentage', 'valeur' => 10, 'inclure_inactifs' => true])
            ->assertOk()->json('lignes');
        $this->assertSame([
            ['CIM-25', null, 33.33, 36.66], ['CIM-50', null, 100.0, 110.0], ['CIM-50', 'Export', 123.0, 135.0],
            ['CIM-OLD', null, 80.0, 88.0], ['FER-10', null, 50.0, 55.0], ['FER-10', 'Export', 61.0, 67.0],
        ], $this->resume($toutes));

        // Un aperçu ne change aucun prix.
        $this->assertSame([33.33, 100.0, 123.0], [$this->prix('CIM-25'), $this->prix('CIM-50'), $this->prix('CIM-50', $this->export)]);

        // Contrôles : la liste visée est requise, et doit être de l'entreprise.
        $this->postJson('/api/revisions-prix/apercu', ['cible' => 'liste', 'type' => 'pourcentage', 'valeur' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['liste_prix_id']);
        $ailleurs = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id, fn () => ListePrix::create(['nom' => 'Ailleurs', 'precision' => 2])->id);
        $this->postJson('/api/revisions-prix/apercu', ['cible' => 'liste', 'liste_prix_id' => $ailleurs, 'type' => 'remise', 'valeur' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['liste_prix_id', 'type', 'valeur']);
    }

    public function test_une_revision_planifiee_s_applique_une_fois_a_sa_date(): void
    {
        $revision = $this->revision();
        $this->assertSame(['brouillon', 2, 'Awa Admin'], [$revision['statut'], $revision['lignes_count'], $revision['cree_par']]);
        $this->assertSame([['CIM-25', null, 33.33, 35.0], ['CIM-50', null, 100.0, 104.5]], $this->resume($revision['lignes']));
        $adresse = "/api/revisions-prix/{$revision['id']}";

        // Lignes contrôlées : un prix une seule fois, des produits de l'entreprise, jamais négatif.
        $autre = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id, fn () => ProduitTenant::create(['numero' => 'X', 'description' => 'X', 'actif' => true])->id);
        $this->postJson('/api/revisions-prix', ['nom' => 'X', 'lignes' => [['produit_id' => $this->id('CIM-25'), 'nouveau_prix' => 1], ['produit_id' => $this->id('CIM-25'), 'liste_prix_id' => null, 'nouveau_prix' => 2]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.1' => ['Ce prix figure deux fois dans la révision.']]);
        $this->postJson('/api/revisions-prix', ['nom' => 'X', 'lignes' => [['produit_id' => $autre, 'nouveau_prix' => 1]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.produit_id' => ['Produit introuvable.']]);
        $this->postJson('/api/revisions-prix', ['nom' => 'X', 'lignes' => [['produit_id' => $this->id('CIM-25'), 'nouveau_prix' => -1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.nouveau_prix']);

        // Retouche : un prix de liste en plus.
        $lignes = [
            ['produit_id' => $this->id('CIM-25'), 'nouveau_prix' => 35],
            ['produit_id' => $this->id('CIM-50'), 'nouveau_prix' => 104.5],
            ['produit_id' => $this->id('CIM-50'), 'liste_prix_id' => $this->export->id, 'nouveau_prix' => 130],
        ];
        $this->putJson($adresse, ['nom' => 'Hausse des liants', 'lignes' => $lignes])->assertOk()->assertJsonPath('lignes_count', 3);

        // Planifier : une date à venir.
        $this->postJson("{$adresse}/planifier", [])->assertUnprocessable()->assertJsonValidationErrors(['date_effet']);
        $this->postJson("{$adresse}/planifier", ['date_effet' => '2026-10-17'])->assertUnprocessable()
            ->assertJsonPath('errors.date_effet.0', "La date d'entrée en vigueur ne peut pas être passée : pour un effet immédiat, appliquez la révision.");
        $this->postJson("{$adresse}/planifier", ['date_effet' => '2026-10-20'])->assertOk()
            ->assertJsonPath('statut', 'planifiee')->assertJsonPath('date_effet', '2026-10-20');
        // La tâche qui applique les révisions, jamais réglée, est activée.
        $liste = $this->getJson('/api/revisions-prix')->assertOk()->json();
        $this->assertSame([true, [$revision['id']], ['planifiee']], [$liste['tache']['active'], array_column($liste['data'], 'id'), array_column($liste['data'], 'statut')]);
        $this->assertSame([[$this->export->id, 'Export'], [$this->gros->id, 'Gros']], array_map(fn ($l) => [$l['id'], $l['nom']], $liste['listes']));
        $this->assertSame(['Aciers', 'Liants'], $liste['categories']);

        // Une révision planifiée garde sa date.
        $this->putJson($adresse, ['nom' => 'Hausse des liants', 'date_effet' => null, 'lignes' => $lignes])->assertUnprocessable()
            ->assertJsonPath('errors.date_effet.0', "Une révision planifiée a besoin de sa date d'entrée en vigueur.");
        $this->putJson($adresse, ['nom' => 'Hausse des liants 2027', 'date_effet' => '2026-10-20', 'lignes' => $lignes])->assertOk()
            ->assertJsonPath('statut', 'planifiee');

        // Avant la date : rien ne change.
        $this->assertSame('Aucune révision de prix à appliquer.', $this->executerTache());
        $this->assertSame([33.33, 100.0, 123.0], [$this->prix('CIM-25'), $this->prix('CIM-50'), $this->prix('CIM-50', $this->export)]);

        // Le jour venu, la tâche applique la révision et le notifie.
        $this->travelTo(Carbon::parse('2026-10-20 00:10:00', 'UTC'));
        $this->assertSame('1 révision(s) appliquée(s), 3 prix mis à jour.', $this->executerTache());
        $this->assertSame([35.0, 104.5, 130.0], [$this->prix('CIM-25'), $this->prix('CIM-50'), $this->prix('CIM-50', $this->export)]);
        $notification = $this->chez(fn () => Notification::where('type', 'revision_prix')->sole());
        $this->assertSame('La révision « Hausse des liants 2027 » est entrée en vigueur : 3 prix mis à jour.', $notification->message);

        // Appliquée par la tâche : les prix remplacés restent visibles.
        $appliquee = $this->getJson($adresse)->assertOk()->json();
        $this->assertSame(['appliquee', null], [$appliquee['statut'], $appliquee['appliquee_par']]);
        $this->assertNotNull($appliquee['appliquee_le']);
        $this->assertSame([['CIM-25', null, 33.33, 35.0], ['CIM-50', null, 100.0, 104.5], ['CIM-50', 'Export', 123.0, 130.0]], $this->resume($appliquee['lignes']));

        // Une seule application ; une révision appliquée ne se modifie, ne s'annule ni ne se supprime.
        $this->assertSame('Aucune révision de prix à appliquer.', $this->executerTache());
        $this->postJson("{$adresse}/appliquer")->assertUnprocessable()->assertJsonPath('errors.statut.0', 'Cette révision a déjà été appliquée.');
        $this->postJson("{$adresse}/annuler")->assertUnprocessable()->assertJsonPath('errors.statut.0', 'Cette révision a déjà été appliquée.');
        $this->putJson($adresse, ['nom' => 'X', 'lignes' => $lignes])->assertUnprocessable()
            ->assertJsonPath('errors.statut.0', 'Une révision appliquée ou annulée ne se modifie plus.');
        $this->deleteJson($adresse)->assertUnprocessable()
            ->assertJsonPath('errors.statut.0', "Une révision appliquée ne se supprime pas : elle garde l'historique des prix.");
        $this->assertSame(104.5, $this->prix('CIM-50'));
    }

    public function test_appliquer_tout_de_suite_annuler_et_supprimer(): void
    {
        // Appliquée tout de suite : le prix saisi manquant dans la liste est créé.
        $revision = $this->revision(['nom' => 'Prix export', 'lignes' => [
            ['produit_id' => $this->id('CIM-25'), 'liste_prix_id' => $this->export->id, 'nouveau_prix' => 40],
            ['produit_id' => $this->id('FER-10'), 'nouveau_prix' => 52],
        ]]);
        $this->assertSame([['CIM-25', 'Export', null, 40.0], ['FER-10', null, 50.0, 52.0]], $this->resume($revision['lignes']));
        $appliquee = $this->postJson("/api/revisions-prix/{$revision['id']}/appliquer")->assertOk()->json();
        $this->assertSame(['Révision appliquée : 2 prix mis à jour.', 'appliquee', 'Awa Admin'], [$appliquee['message'], $appliquee['statut'], $appliquee['appliquee_par']]);
        $this->assertSame([40.0, 52.0], [$this->prix('CIM-25', $this->export), $this->prix('FER-10')]);
        $this->assertSame([['CIM-25', 'Export', null, 40.0], ['FER-10', null, 50.0, 52.0]], $this->resume($appliquee['lignes']));
        // Appliquée par un compte : pas de notification.
        $this->assertSame(0, $this->chez(fn () => Notification::where('type', 'revision_prix')->count()));

        // Annulée : elle ne s'applique plus, mais se supprime.
        $annulee = $this->revision();
        $this->postJson("/api/revisions-prix/{$annulee['id']}/planifier", ['date_effet' => '2026-10-19'])->assertOk();
        $this->postJson("/api/revisions-prix/{$annulee['id']}/annuler")->assertOk()
            ->assertJsonPath('statut', 'annulee')->assertJsonPath('annulee_par', 'Awa Admin');
        $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));
        $this->assertSame('Aucune révision de prix à appliquer.', $this->executerTache());
        $this->postJson("/api/revisions-prix/{$annulee['id']}/appliquer")->assertUnprocessable()->assertJsonPath('errors.statut.0', 'Cette révision est annulée.');
        $this->postJson("/api/revisions-prix/{$annulee['id']}/planifier", ['date_effet' => '2026-10-25'])->assertUnprocessable()
            ->assertJsonPath('errors.statut.0', 'Cette révision est annulée.');
        $this->deleteJson("/api/revisions-prix/{$annulee['id']}")->assertOk()->assertJsonPath('message', 'Révision supprimée.');
        $this->assertSame(100.0, $this->prix('CIM-50'));

        // Sans prix, une révision ne se planifie ni ne s'applique ; un brouillon se supprime.
        $vide = $this->revision(['nom' => 'Vide', 'lignes' => []]);
        $this->postJson("/api/revisions-prix/{$vide['id']}/planifier", ['date_effet' => '2026-10-25'])->assertUnprocessable()
            ->assertJsonPath('errors.lignes.0', "Cette révision n'a aucun prix à appliquer.");
        $this->postJson("/api/revisions-prix/{$vide['id']}/appliquer")->assertUnprocessable()
            ->assertJsonPath('errors.lignes.0', "Cette révision n'a aucun prix à appliquer.");
        $this->deleteJson("/api/revisions-prix/{$vide['id']}")->assertOk();
        $this->assertSame([$revision['id']], array_column($this->getJson('/api/revisions-prix')->assertOk()->json('data'), 'id'));
    }

    public function test_droits_isolation_et_planificateur(): void
    {
        $revision = $this->revision();
        $this->postJson("/api/revisions-prix/{$revision['id']}/planifier", ['date_effet' => '2026-10-19'])->assertOk();

        // Sans l'action « Planifier une révision de prix » : consulter seulement.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['gestion_prix.reviser' => false]])->assertOk();
        $this->agir($vendeur);
        $this->getJson('/api/revisions-prix')->assertOk();
        $this->getJson("/api/revisions-prix/{$revision['id']}")->assertOk();
        $this->postJson('/api/revisions-prix/apercu', ['cible' => 'base', 'type' => 'pourcentage', 'valeur' => 5])->assertForbidden();
        $this->postJson('/api/revisions-prix', ['nom' => 'X', 'lignes' => []])->assertForbidden();
        $this->postJson("/api/revisions-prix/{$revision['id']}/appliquer")->assertForbidden();
        $this->deleteJson("/api/revisions-prix/{$revision['id']}")->assertForbidden();
        // Le droit de modifier les tarifs donne l'action (son droit de base).
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => []])->assertOk();
        $this->agir($vendeur);
        $this->postJson('/api/revisions-prix', ['nom' => 'Brouillon du manager', 'lignes' => []])->assertCreated();
        // Sans le droit de voir les tarifs : rien.
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->getJson('/api/revisions-prix')->assertForbidden();

        // Une autre entreprise ne voit ni ne touche les révisions.
        $this->agir(AdminUser::factory()->for(Tenant::factory()->create(['modules' => ['ventes']]))->create(['role' => 'admin']));
        $this->assertSame([], $this->getJson('/api/revisions-prix')->assertOk()->json('data'));
        $this->getJson("/api/revisions-prix/{$revision['id']}")->assertNotFound();
        $this->postJson("/api/revisions-prix/{$revision['id']}/appliquer")->assertNotFound();

        // Le planificateur applique la révision le jour venu (tâche activée à la planification, à 0 h 05).
        $this->travelTo(Carbon::parse('2026-10-19 00:06:00', 'UTC'));
        $this->assertSame(1, app(Planificateur::class)->executerTachesDues());
        $this->assertSame([35.0, 104.5], [$this->prix('CIM-25'), $this->prix('CIM-50')]);
    }
}
