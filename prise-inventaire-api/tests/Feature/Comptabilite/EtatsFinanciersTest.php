<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bilan et compte de résultat (phase 4.8), avant et après la clôture de l'exercice. */
class EtatsFinanciersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $exercice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->json();

        $this->putJson('/api/comptabilite/soldes-ouverture', ['lignes' => [
            ['compte_id' => $this->compte('521'), 'debit' => 1500000], ['compte_id' => $this->compte('101'), 'credit' => 1500000],
        ]])->assertOk();
        $this->ecriture('2026-02-10', [['244', 300000, 0], ['521', 0, 300000]]);
        $this->ecriture('2026-03-05', [['571', 80000, 0], ['701', 0, 80000]]);
        $this->ecriture('2026-04-01', [['622', 20000, 0], ['571', 0, 20000]]);
        $this->ecriture('2026-09-30', [['681', 30000, 0], ['284', 0, 30000]]);
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

    /** [numéro du groupe => montant] d'une section. */
    private function groupes(array $section): array
    {
        return array_column($section['groupes'], 'montant', 'numero');
    }

    public function test_bilan_et_compte_de_resultat_avant_et_apres_cloture(): void
    {
        // Avant clôture : le résultat de l'exercice figure dans les capitaux propres.
        $bilan = $this->getJson('/api/comptabilite/etats/bilan?date=2026-12-31')->assertOk()->json();
        $this->assertSame(['24' => 300000, '28' => -30000, '52' => 1200000, '57' => 60000], $this->groupes($bilan['actif']));
        $this->assertSame(1530000, $bilan['total_actif']);
        $this->assertSame(30000, $bilan['resultat_non_cloture']);
        $this->assertSame(['10' => 1500000, '' => 30000], $this->groupes($bilan['capitaux_propres']));
        $this->assertSame('Résultat non encore clôturé', $bilan['capitaux_propres']['groupes'][1]['libelle']);
        $this->assertSame([1530000, true], [$bilan['total_passif'], $bilan['equilibre']]);
        $this->assertSame([['284', 'Amortissements du matériel', -30000]], array_map(fn ($c) => [$c['numero'], $c['libelle'], $c['montant']], $bilan['actif']['groupes'][1]['comptes']));

        // À une date antérieure : seulement ce qui précède.
        $fevrier = $this->getJson('/api/comptabilite/etats/bilan?date=2026-02-28')->json();
        $this->assertSame([1500000, 0], [$fevrier['total_actif'], $fevrier['resultat_non_cloture']]);

        $resultat = $this->getJson('/api/comptabilite/etats/resultat')->assertOk()->assertJsonPath('du', '2026-01-01')->assertJsonPath('au', '2026-12-31')->json();
        $this->assertSame(['70' => 80000], $this->groupes($resultat['produits']));
        $this->assertSame(['62' => 20000, '68' => 30000], $this->groupes($resultat['charges']));
        $this->assertSame(30000, $resultat['resultat']);

        // Après clôture : le résultat est au compte 131 ; le compte de résultat ignore l'écriture de clôture.
        $this->postJson("/api/comptabilite/exercices/{$this->exercice['id']}/cloturer")->assertOk();
        $bilan = $this->getJson('/api/comptabilite/etats/bilan?date=2026-12-31')->json();
        $this->assertSame(['10' => 1500000, '13' => 30000], $this->groupes($bilan['capitaux_propres']));
        $this->assertSame([0, true], [$bilan['resultat_non_cloture'], $bilan['equilibre']]);
        $this->getJson('/api/comptabilite/etats/resultat?du=2026-01-01&au=2026-12-31')->assertJsonPath('resultat', 30000)->assertJsonPath('produits.total', 80000);
    }

    public function test_exports_csv(): void
    {
        $csv = $this->get('/api/comptabilite/etats/bilan?date=2026-12-31&format=csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFsection;groupe;libelle_groupe;compte;libelle;montant", $csv);
        $this->assertStringContainsString('Actif;28;Amortissements;284;"Amortissements du matériel";-30000,00', $csv);
        $this->assertStringContainsString('"Capitaux propres";;"Résultat non encore clôturé";;;30000,00', $csv);
        $this->assertStringContainsString(';;"Total passif";;;1530000,00', $csv);

        $csv = $this->get('/api/comptabilite/etats/resultat?du=2026-01-01&au=2026-12-31&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('Charges;68;"Dotations aux amortissements";681;"Dotations aux amortissements d\'exploitation";30000,00', $csv);
        $this->assertStringContainsString(';;Résultat;;;30000,00', $csv);
    }
}
