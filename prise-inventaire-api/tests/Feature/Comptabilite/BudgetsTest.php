<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Budgets (phase 4.7) : comptes budgétables, feuille d'un exercice (cellules, totaux, résultat, total de l'exercice
 * précédent), cellule retirée par un montant nul, répartition au centime, copie ajustée entre découpages différents
 * (mois → trimestres → mois), remplir ou remplacer ; budget et réel (hors clôture, hors budget à part, favorable),
 * CSV ; budget figé à la clôture ; protections du plan et des exercices ; journal ; droits et isolation.
 */
class BudgetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private array $exercice2026;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->exercice2026 = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])
            ->assertCreated()->json();
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function compte(string $numero): int
    {
        return $this->chez(fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    private function periode(array $exercice, int $numero): int
    {
        return $exercice['periodes'][$numero - 1]['id'];
    }

    private function ecriture(string $date, array $lignes): void
    {
        $journal = collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', 'OD')['id'];
        $this->postJson('/api/comptabilite/ecritures', [
            'journal_id' => $journal, 'date' => $date, 'libelle' => 'Opération',
            'lignes' => array_map(fn ($l) => ['compte_id' => $this->compte($l[0]), 'debit' => $l[1], 'credit' => $l[2]], $lignes),
        ])->assertCreated();
    }

    private function cellules(array $exercice, array $cellules): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/budgets', ['exercice_id' => $exercice['id'], 'cellules' => array_map(fn ($c) => [
            'compte_id' => $this->compte($c[0]), 'periode_id' => $this->periode($exercice, $c[1]), 'montant' => $c[2],
        ], $cellules)]);
    }

    /** Montants d'un compte dans une feuille : numéro de période => montant. */
    private function ligne(array $feuille, string $numero): array
    {
        $ligne = collect($feuille['comptes'])->firstWhere('numero', $numero);
        $numeros = collect($feuille['periodes'])->pluck('numero', 'id');

        return ['montants' => collect($ligne['montants'])->mapWithKeys(fn ($m, $periode) => [$numeros[$periode] => $m])->sortKeys()->all()] + $ligne;
    }

    public function test_feuille_saisie_repartition_comparaison_copie_et_protections(): void
    {
        // Feuille vide : aucun compte n'est encore budgétable ; un compte non budgétable est refusé.
        $this->getJson('/api/budgets')->assertOk()->assertJsonPath('exercice.id', $this->exercice2026['id'])
            ->assertJsonPath('modifiable', true)->assertJsonCount(0, 'comptes')->assertJsonCount(12, 'periodes');
        $this->cellules($this->exercice2026, [['701', 1, 1000]])->assertUnprocessable()
            ->assertJsonValidationErrors(['cellules.0' => "Le compte 701 n'est pas budgétable : ajoutez-le aux comptes du budget."]);

        // Comptes du budget : un en-tête est écarté.
        $this->putJson('/api/budgets/comptes', ['comptes' => [$this->compte('701'), $this->compte('601'), $this->compte('631'), $this->compte('70')], 'budgetable' => true])
            ->assertOk()->assertJsonPath('modifies', 3);
        $this->cellules($this->exercice2026, [['701', 1, 1000], ['701', 2, 1200], ['601', 1, 600]])->assertOk()
            ->assertJsonPath('totaux.produits', 2200)->assertJsonPath('totaux.charges', 600)->assertJsonPath('totaux.resultat', 1600);
        // Un montant nul retire la cellule ; un total réparti au centime, le reste sur la dernière période.
        $this->cellules($this->exercice2026, [['701', 2, 0]])->assertOk()->assertJsonPath('totaux.produits', 1000);
        $feuille = $this->postJson('/api/budgets/repartir', ['exercice_id' => $this->exercice2026['id'], 'compte_id' => $this->compte('631'), 'total' => 1000])
            ->assertOk()->json();
        $frais = $this->ligne($feuille, '631');
        $this->assertEquals([1000, 83.33, 83.37, 12], [$frais['total'], $frais['montants'][1], $frais['montants'][12], count($frais['montants'])]);
        $this->assertSame(['601', '631', '701'], array_column($feuille['comptes'], 'numero'));
        $p1 = $this->periode($this->exercice2026, 1);
        $this->assertEquals(['produits' => 1000, 'charges' => 683.33, 'resultat' => 316.67], $feuille['totaux']['par_periode'][$p1]);
        // Chaque cellule est au journal des modifications ; la répartition aussi.
        $journal = $this->chez(fn () => AuditLog::where('model_type', 'Budget')->orderBy('id')->get());
        // Trois cellules saisies, une retirée, douze réparties.
        $this->assertSame(16, $journal->count());
        $this->assertEquals(['create', 'Awa Comptable', 1000], [$journal[0]->action, $journal[0]->user_name, $journal[0]->new_values['montant']]);
        $this->assertSame('delete', $journal[3]->action);

        // Le réel : janvier (ventes 900, achats 700, intérêts hors budget 50) et février (achats 100).
        $this->ecriture('2026-01-10', [['411', 900, 0], ['701', 0, 900]]);
        $this->ecriture('2026-01-12', [['601', 700, 0], ['401', 0, 700]]);
        $this->ecriture('2026-01-20', [['571', 50, 0], ['771', 0, 50]]);
        $this->ecriture('2026-02-15', [['601', 100, 0], ['401', 0, 100]]);
        $janvier = $this->getJson("/api/budgets/comparaison?periode_du={$p1}&periode_au={$p1}")->assertOk()->json();
        $this->assertEquals([
            ['601', 600, 700, 100, 116.7, false],
            ['631', 83.33, 0, -83.33, 0, true],
            ['701', 1000, 900, -100, 90, false],
        ], array_map(fn ($l) => [$l['numero'], $l['budget'], $l['reel'], $l['ecart'], $l['taux'], $l['favorable']], $janvier['lignes']));
        $this->assertEquals(['produits' => 50, 'charges' => 0], $janvier['hors_budget']);
        $this->assertEquals([
            'produits' => ['budget' => 1000, 'reel' => 950, 'ecart' => -50],
            'charges' => ['budget' => 683.33, 'reel' => 700, 'ecart' => 16.67],
            'resultat' => ['budget' => 316.67, 'reel' => 250, 'ecart' => -66.67],
        ], $janvier['totaux']);
        $this->assertSame(['2026-01-01', '2026-01-31'], [$janvier['du'], $janvier['au']]);
        $csv = $this->get("/api/budgets/comparaison?periode_du={$p1}&periode_au={$p1}&format=csv")->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('701;"Ventes de marchandises";produit;1000,00;900,00;-100,00;90', $csv->getContent());
        $this->assertStringContainsString(';Résultat;;316,67;250,00;-66,67;', $csv->getContent());
        $this->getJson('/api/budgets/comparaison?periode_du='.$this->periode($this->exercice2026, 3)."&periode_au={$p1}")->assertUnprocessable()
            ->assertJsonValidationErrors('periode_au');

        // Copie vers un exercice trimestriel, + 10 % : les mois s'additionnent dans leur trimestre.
        $exercice2027 = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-01-01', 'date_fin' => '2027-12-31', 'decoupage' => 'trimestriel'])
            ->assertCreated()->json();
        $copie = $this->postJson('/api/budgets/copier', ['exercice_id' => $exercice2027['id'], 'source_exercice_id' => $this->exercice2026['id'], 'pourcentage' => 10])
            ->assertOk()->assertJsonPath('message', "Budget de l'exercice 2026 copié : 6 cellule(s), 3 compte(s).")->json();
        $this->assertEquals([1 => 1100], $this->ligne($copie, '701')['montants']);
        $this->assertEquals([1 => 660], $this->ligne($copie, '601')['montants']);
        $this->assertEquals([1 => 274.98, 2 => 274.98, 3 => 274.98, 4 => 275.03], $this->ligne($copie, '631')['montants']);
        $this->assertEquals([1000, ['id' => $this->exercice2026['id'], 'libelle' => '2026']], [$this->ligne($copie, '701')['total_precedent'], $copie['precedent']]);
        // Sans remplacer, une cellule saisie reste ; en remplaçant, elle reprend la copie.
        $this->cellules($exercice2027, [['701', 1, 1500]])->assertOk();
        $this->postJson('/api/budgets/copier', ['exercice_id' => $exercice2027['id'], 'source_exercice_id' => $this->exercice2026['id'], 'pourcentage' => 10])
            ->assertOk()->assertJsonPath('resultat.cellules', 0);
        $this->assertEquals([1 => 1500], $this->ligne($this->getJson("/api/budgets?exercice_id={$exercice2027['id']}")->json(), '701')['montants']);
        $remplace = $this->postJson('/api/budgets/copier', ['exercice_id' => $exercice2027['id'], 'source_exercice_id' => $this->exercice2026['id'], 'pourcentage' => 10, 'remplacer' => true])
            ->assertOk()->json();
        $this->assertEquals([1 => 1100], $this->ligne($remplace, '701')['montants']);
        // Et d'un trimestre vers ses trois mois, au centime.
        $exercice2028 = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2028-01-01', 'date_fin' => '2028-12-31', 'decoupage' => 'mensuel'])
            ->assertCreated()->json();
        $mensuel = $this->postJson('/api/budgets/copier', ['exercice_id' => $exercice2028['id'], 'source_exercice_id' => $exercice2027['id']])->assertOk()->json();
        $this->assertEquals([1 => 366.66, 2 => 366.66, 3 => 366.68], $this->ligne($mensuel, '701')['montants']);
        $this->postJson('/api/budgets/copier', ['exercice_id' => $exercice2028['id'], 'source_exercice_id' => $exercice2028['id']])->assertUnprocessable()
            ->assertJsonPath('message', 'Choisissez un autre exercice que celui du budget.');

        // Protections : un compte budgété ne se supprime pas ni ne devient un en-tête ; un exercice budgété garde ses périodes.
        $this->deleteJson('/api/comptabilite/comptes/'.$this->compte('631'))->assertUnprocessable()
            ->assertJsonPath('message', 'Ce compte a un budget : retirez ses montants, ou désactivez-le plutôt.');
        $frais = collect($this->getJson('/api/comptabilite/comptes')->json())->firstWhere('numero', '631');
        $this->putJson("/api/comptabilite/comptes/{$frais['id']}", ['type' => 'en_tete'] + array_intersect_key($frais, array_flip(['numero', 'libelle', 'parent_id', 'nature', 'actif'])))
            ->assertUnprocessable()->assertJsonPath('errors.type.0', 'Ce compte a un budget : il ne peut pas devenir un en-tête.');
        $this->putJson("/api/comptabilite/exercices/{$exercice2028['id']}", ['date_debut' => '2028-01-01', 'date_fin' => '2028-12-31', 'decoupage' => 'trimestriel'])
            ->assertUnprocessable()->assertJsonPath('message', 'Cet exercice a un budget : ses dates et son découpage ne peuvent plus changer.');
        $this->deleteJson("/api/comptabilite/exercices/{$exercice2028['id']}")->assertUnprocessable()
            ->assertJsonPath('message', 'Cet exercice a un budget : il ne peut pas être supprimé.');

        // Clôture de 2026 : le réel ignore l'écriture de clôture ; le budget de l'exercice clôturé est figé.
        $this->postJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/cloturer")->assertOk();
        $annee = $this->getJson("/api/budgets/comparaison?exercice_id={$this->exercice2026['id']}")->assertOk()->json();
        $this->assertEquals([800, 900], [collect($annee['lignes'])->firstWhere('numero', '601')['reel'], collect($annee['lignes'])->firstWhere('numero', '701')['reel']]);
        $this->getJson("/api/budgets?exercice_id={$this->exercice2026['id']}")->assertOk()->assertJsonPath('modifiable', false);
        $this->cellules($this->exercice2026, [['701', 3, 10]])->assertUnprocessable()
            ->assertJsonPath('message', "L'exercice 2026 est clôturé : son budget ne se modifie plus.");
        $this->postJson('/api/budgets/copier', ['exercice_id' => $this->exercice2026['id'], 'source_exercice_id' => $exercice2027['id']])->assertUnprocessable();

        // Retiré du budget, un compte reste dans la feuille tant qu'il a des montants.
        $this->putJson('/api/budgets/comptes', ['comptes' => [$this->compte('631')], 'budgetable' => false])->assertOk();
        $this->assertNotNull($this->ligne($this->getJson("/api/budgets?exercice_id={$exercice2027['id']}")->json(), '631')['id']);
    }

    public function test_droits_et_isolation(): void
    {
        $this->putJson('/api/budgets/comptes', ['comptes' => [$this->compte('701')], 'budgetable' => true])->assertOk();
        $this->cellules($this->exercice2026, [['701', 1, 1000]])->assertOk();

        // Consulter sans modifier ni voir les états : la feuille, mais ni saisie ni comparaison au réel.
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['budgets.voir' => true, 'budgets.saisir' => false, 'comptabilite.etats' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson('/api/budgets')->assertOk()->assertJsonPath('comptes.0.total', 1000);
        $this->cellules($this->exercice2026, [['701', 1, 5]])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Modifier les montants du budget » (Budgets).');
        $this->postJson('/api/budgets/copier', ['exercice_id' => $this->exercice2026['id'], 'source_exercice_id' => $this->exercice2026['id']])->assertForbidden();
        $this->putJson('/api/budgets/comptes', ['comptes' => [$this->compte('601')], 'budgetable' => true])->assertForbidden();
        $this->getJson('/api/budgets/comparaison')->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Voir les états comptables » (Comptabilité).');

        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['budgets.voir' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson('/api/budgets')->assertForbidden();

        // Une autre entreprise ne voit ni ne touche ce budget.
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/budgets?exercice_id={$this->exercice2026['id']}")->assertNotFound();
        $this->putJson('/api/budgets', ['exercice_id' => $this->exercice2026['id'], 'cellules' => [['compte_id' => 1, 'periode_id' => 1, 'montant' => 5]]])->assertNotFound();
        $this->getJson('/api/budgets')->assertOk()->assertJsonPath('exercice', null);
    }
}
