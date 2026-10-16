<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Clôture d'exercice : report du résultat, fermeture des périodes, conditions, réouverture. */
class ClotureExerciceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $exercice2026;

    private array $exercice2027;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-02-10 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->exercice2026 = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->json();
        $this->exercice2027 = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-01-01', 'date_fin' => '2027-12-31', 'decoupage' => 'mensuel'])->json();
    }

    private function compte(string $numero): int
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    private function ecriture(string $date, array $lignes): void
    {
        $journal = collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', 'OD')['id'];
        $this->postJson('/api/comptabilite/ecritures', [
            'journal_id' => $journal, 'date' => $date, 'libelle' => 'Opération',
            'lignes' => array_map(fn ($l) => ['compte_id' => $this->compte($l[0]), 'debit' => $l[1], 'credit' => $l[2]], $lignes),
        ])->assertCreated();
    }

    public function test_cloture_reporte_le_resultat_et_ferme_les_periodes(): void
    {
        $this->ecriture('2026-03-10', [['411', 238500, 0], ['701', 0, 200000], ['443', 0, 38500]]);
        $this->ecriture('2026-05-02', [['601', 120000, 0], ['401', 0, 120000]]);
        $this->ecriture('2026-06-30', [['622', 30000, 0], ['571', 0, 30000]]);

        $id = $this->exercice2026['id'];
        $this->getJson("/api/comptabilite/exercices/{$id}/cloture")->assertOk()
            ->assertJsonPath('produits', 200000)->assertJsonPath('charges', 150000)->assertJsonPath('resultat', 50000)
            ->assertJsonPath('compte.numero', '131')->assertJsonPath('bloquant', null);

        // Les administrateurs seuls clôturent.
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']), ['*']);
        $this->postJson("/api/comptabilite/exercices/{$id}/cloturer")->assertForbidden();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);

        $cloture = $this->postJson("/api/comptabilite/exercices/{$id}/cloturer")->assertOk()->assertJsonPath('statut', 'cloture')->json();
        $this->assertSame(array_fill(0, 12, true), array_column($cloture['periodes'], 'fermee'));

        $ecriture = $this->getJson('/api/comptabilite/ecritures?source=cloture')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['2026-12-31', 'Clôture de l\'exercice 2026', false], [$ecriture['date'], $ecriture['libelle'], $ecriture['modifiable']]);
        $this->assertSame([['601', 0, 120000], ['622', 0, 30000], ['701', 200000, 0], ['131', 0, 50000]],
            array_map(fn ($l) => [$l['compte'], $l['debit'], $l['credit']], $ecriture['lignes']));
        $this->postJson("/api/comptabilite/ecritures/{$ecriture['id']}/contre-passer", ['date' => '2027-02-10'])->assertUnprocessable();

        // Exercice suivant : comptes de résultat à zéro, bénéfice au crédit du 131.
        $balance = collect($this->getJson('/api/comptabilite/balance?du=2027-01-01&au=2027-12-31')->assertJsonPath('equilibree', true)->json('comptes'))->keyBy('numero');
        $this->assertSame(50000, $balance['131']['ouverture_credit']);
        $this->assertFalse($balance->has('701'));
        $this->assertFalse($balance->has('601'));

        // Plus aucune écriture dans un exercice clôturé.
        $journal = collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', 'OD')['id'];
        $this->postJson('/api/comptabilite/ecritures', ['journal_id' => $journal, 'date' => '2026-12-15', 'libelle' => 'Tardive',
            'lignes' => [['compte_id' => $this->compte('622'), 'debit' => 10], ['compte_id' => $this->compte('571'), 'credit' => 10]]])
            ->assertUnprocessable()->assertJsonPath('errors.date.0', 'L\'exercice 2026 est clôturé.');
        $this->getJson("/api/comptabilite/exercices/{$id}/cloture")->assertJsonPath('bloquant', 'Cet exercice est déjà clôturé.');
    }

    public function test_perte_conditions_et_reouverture(): void
    {
        $this->ecriture('2026-04-01', [['622', 80000, 0], ['571', 0, 80000]]);
        $this->ecriture('2027-01-15', [['622', 1000, 0], ['571', 0, 1000]]);

        // L'exercice précédent d'abord.
        $this->getJson("/api/comptabilite/exercices/{$this->exercice2027['id']}/cloture")->assertJsonPath('bloquant', 'Clôturez d\'abord l\'exercice 2026.');
        $this->postJson("/api/comptabilite/exercices/{$this->exercice2027['id']}/cloturer")->assertUnprocessable();

        // Un compte de résultat est nécessaire dès qu'il y a des produits ou des charges.
        $resultat = $this->compte('131');
        $this->putJson("/api/comptabilite/comptes/{$resultat}", ['numero' => '131', 'libelle' => 'Résultat net : bénéfice', 'nature' => 'capitaux_propres', 'type' => 'standard', 'parent_id' => $this->compte('13')])->assertOk();
        $this->getJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/cloture")
            ->assertJsonPath('bloquant', 'Désignez d\'abord, dans le plan comptable, le compte qui reçoit le résultat (type « Résultat de l\'exercice »).');
        $this->putJson("/api/comptabilite/comptes/{$resultat}", ['numero' => '131', 'libelle' => 'Résultat net', 'nature' => 'capitaux_propres', 'type' => 'resultat', 'parent_id' => $this->compte('13')])->assertOk();

        // Perte : le compte de résultat est débité ; toutes les périodes étaient déjà fermées, la clôture passe quand même.
        foreach ($this->exercice2026['periodes'] as $periode) {
            $this->postJson("/api/comptabilite/periodes/{$periode['id']}/fermer")->assertOk();
        }
        $this->getJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/cloture")->assertJsonPath('resultat', -80000);
        $this->postJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/cloturer")->assertOk();
        $lignes = $this->getJson('/api/comptabilite/ecritures?source=cloture')->json('data.0.lignes');
        $this->assertSame([['622', 0, 80000], ['131', 80000, 0]], array_map(fn ($l) => [$l['compte'], $l['debit'], $l['credit']], $lignes));

        $this->postJson("/api/comptabilite/exercices/{$this->exercice2027['id']}/cloturer")->assertOk();
        // Réouverture : la plus récente d'abord ; l'écriture de clôture disparaît, les périodes restent fermées.
        $this->postJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/rouvrir")->assertUnprocessable()
            ->assertJsonPath('errors.exercice.0', 'Rouvrez d\'abord l\'exercice 2027, clôturé lui aussi.');
        $this->postJson("/api/comptabilite/exercices/{$this->exercice2027['id']}/rouvrir")->assertOk()->assertJsonPath('statut', 'ouvert');
        $rouvert = $this->postJson("/api/comptabilite/exercices/{$this->exercice2026['id']}/rouvrir")->assertOk()->assertJsonPath('statut', 'ouvert')->json();
        $this->assertSame(array_fill(0, 12, true), array_column($rouvert['periodes'], 'fermee'));
        $this->getJson('/api/comptabilite/ecritures?source=cloture')->assertJsonCount(0, 'data');
    }
}
