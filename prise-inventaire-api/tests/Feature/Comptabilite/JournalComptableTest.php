<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Journal général (phase 4.2) : journaux, écritures équilibrées et numérotées, périodes fermées, modification,
 * contre-passation, filtres, protections du plan et des exercices.
 */
class JournalComptableTest extends TestCase
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
    }

    private function compte(string $numero): int
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    private function journal(string $code): int
    {
        return collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', $code)['id'];
    }

    /** @param  list<array{0: string, 1: float, 2: float}>  $lignes  [compte, débit, crédit] */
    private function ecriture(string $date, array $lignes, string $libelle = 'Écriture', string $journal = 'OD'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/comptabilite/ecritures', [
            'journal_id' => $this->journal($journal),
            'date' => $date,
            'libelle' => $libelle,
            'lignes' => array_map(fn ($l) => ['compte_id' => $this->compte($l[0]), 'debit' => $l[1], 'credit' => $l[2]], $lignes),
        ]);
    }

    private function periode(int $mois): int
    {
        return $this->exercice['periodes'][$mois - 1]['id'];
    }

    public function test_journaux_par_defaut_et_reglage(): void
    {
        $this->assertSame(['AC', 'AN', 'BQ', 'CA', 'OD', 'VE'], array_column($this->getJson('/api/comptabilite/journaux')->assertOk()->json(), 'code'));

        $od2 = $this->postJson('/api/comptabilite/journaux', ['code' => ' od2 ', 'libelle' => 'Opérations diverses 2', 'type' => 'general'])
            ->assertCreated()->assertJsonPath('code', 'OD2')->json();
        $this->postJson('/api/comptabilite/journaux', ['code' => 'OD', 'libelle' => 'Doublon', 'type' => 'general'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/comptabilite/journaux', ['code' => 'O-D', 'libelle' => 'Tiret', 'type' => 'general'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->ecriture('2026-02-10', [['601', 100, 0], ['571', 0, 100]])->assertCreated();
        $od = $this->journal('OD');
        $this->putJson("/api/comptabilite/journaux/{$od}", ['code' => 'ODX', 'libelle' => 'Opérations diverses', 'type' => 'general'])->assertUnprocessable();
        $this->putJson("/api/comptabilite/journaux/{$od}", ['code' => 'OD', 'libelle' => 'Opérations diverses (renommé)', 'type' => 'general'])->assertOk();
        $this->deleteJson("/api/comptabilite/journaux/{$od}")->assertUnprocessable();
        $this->deleteJson("/api/comptabilite/journaux/{$od2['id']}")->assertOk();
    }

    public function test_ecriture_equilibree_numerotee_et_controlee(): void
    {
        $achat = $this->ecriture('2026-03-12', [['601', 1000, 0], ['445', 192.5, 0], ['401', 0, 1192.5]], 'Achat de marchandises')
            ->assertCreated()->assertJsonPath('numero', 'OD-2026-00001')->assertJsonPath('total', 1192.5)->assertJsonPath('modifiable', true)->json();
        $this->assertSame(['601', '445', '401'], array_column($achat['lignes'], 'compte'));
        $this->ecriture('2026-03-13', [['601', 50, 0], ['571', 0, 50]])->assertCreated()->assertJsonPath('numero', 'OD-2026-00002');

        $this->ecriture('2026-03-14', [['601', 1000, 0], ['401', 0, 900]])->assertUnprocessable()
            ->assertJsonPath('message', 'L\'écriture n\'est pas équilibrée : débit 1 000,00, crédit 900,00 (écart 100,00).');
        $this->ecriture('2026-03-14', [['601', 100, 100], ['401', 0, 100]])->assertUnprocessable()
            ->assertJsonPath('errors', ['lignes.0' => ['Ligne 1 : un débit ou un crédit, pas les deux.']]);
        $this->ecriture('2026-03-14', [['60', 100, 0], ['401', 0, 100]])->assertUnprocessable()
            ->assertJsonPath('errors', ['lignes.0' => ['Ligne 1 : le compte 60 est un en-tête : choisissez l\'un de ses sous-comptes.']]);
        $this->ecriture('2026-03-14', [['601', 100, 0]])->assertUnprocessable()->assertJsonValidationErrors('lignes');

        $compte411 = $this->compte('411');
        $this->putJson("/api/comptabilite/comptes/{$compte411}", ['numero' => '411', 'libelle' => 'Clients', 'nature' => 'actif', 'type' => 'clients', 'parent_id' => $this->compte('41'), 'saisie_manuelle' => false])->assertOk();
        $this->ecriture('2026-03-14', [['411', 100, 0], ['701', 0, 100]])->assertUnprocessable()
            ->assertJsonPath('errors', ['lignes.0' => ['Ligne 1 : le compte 411 n\'accepte pas la saisie manuelle.']]);

        $this->ecriture('2025-12-31', [['601', 100, 0], ['571', 0, 100]])->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Aucun exercice ne couvre le 31/12/2025 : créez-le dans Exercices comptables.');
        $this->postJson('/api/comptabilite/periodes/'.$this->periode(3).'/fermer')->assertOk();
        $this->ecriture('2026-03-20', [['601', 100, 0], ['571', 0, 100]])->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'La période du 01/03/2026 au 31/03/2026 est fermée.');
    }

    public function test_modification_suppression_et_contrepassation(): void
    {
        $ecriture = $this->ecriture('2026-02-10', [['622', 300, 0], ['571', 0, 300]], 'Loyer de février')->assertCreated()->json();
        $saisie = fn (string $journal, float $montant) => [
            'journal_id' => $this->journal($journal), 'date' => '2026-02-10', 'libelle' => 'Loyer de février', 'reference' => 'QUITTANCE-02',
            'lignes' => [['compte_id' => $this->compte('622'), 'debit' => $montant], ['compte_id' => $this->compte('571'), 'credit' => $montant]],
        ];

        $this->putJson("/api/comptabilite/ecritures/{$ecriture['id']}", $saisie('OD', 350))->assertOk()
            ->assertJsonPath('numero', 'OD-2026-00001')->assertJsonPath('total', 350)->assertJsonPath('reference', 'QUITTANCE-02');
        $this->putJson("/api/comptabilite/ecritures/{$ecriture['id']}", $saisie('CA', 350))->assertOk()->assertJsonPath('numero', 'CA-2026-00001');

        // Période fermée : ni modification ni suppression ; la contre-passation corrige dans une période ouverte.
        $this->postJson('/api/comptabilite/periodes/'.$this->periode(2).'/fermer')->assertOk();
        $this->getJson("/api/comptabilite/ecritures/{$ecriture['id']}")->assertJsonPath('modifiable', false);
        $this->putJson("/api/comptabilite/ecritures/{$ecriture['id']}", $saisie('CA', 400))->assertUnprocessable();
        $this->deleteJson("/api/comptabilite/ecritures/{$ecriture['id']}")->assertUnprocessable();

        $inverse = $this->postJson("/api/comptabilite/ecritures/{$ecriture['id']}/contre-passer", ['date' => '2026-10-16'])->assertCreated()
            ->assertJsonPath('numero', 'CA-2026-00002')->assertJsonPath('reference', 'CA-2026-00001')
            ->assertJsonPath('contrepassation_de', 'CA-2026-00001')->assertJsonPath('libelle', 'Contre-passation de CA-2026-00001 : Loyer de février')->json();
        $this->assertSame([['622', 0, 350], ['571', 350, 0]], array_map(fn ($l) => [$l['compte'], $l['debit'], $l['credit']], $inverse['lignes']));
        $this->getJson("/api/comptabilite/ecritures/{$ecriture['id']}")->assertJsonPath('contrepassee_par', 'CA-2026-00002');
        $this->postJson("/api/comptabilite/ecritures/{$ecriture['id']}/contre-passer", ['date' => '2026-10-16'])->assertUnprocessable();

        // L'original ne se supprime pas tant que sa contre-passation existe.
        $this->postJson('/api/comptabilite/periodes/'.$this->periode(2).'/rouvrir')->assertOk();
        $this->deleteJson("/api/comptabilite/ecritures/{$ecriture['id']}")->assertUnprocessable();
        $this->deleteJson("/api/comptabilite/ecritures/{$inverse['id']}")->assertOk();
        $this->deleteJson("/api/comptabilite/ecritures/{$ecriture['id']}")->assertOk();
        // Numérotation : rang suivant du plus grand numéro restant. Pas de trou en fin de journal, et un numéro
        // supprimé au milieu n'est pas réattribué.
        $a = $this->ecriture('2026-02-11', [['622', 10, 0], ['571', 0, 10]], 'A', 'CA')->assertCreated()->assertJsonPath('numero', 'CA-2026-00001')->json('id');
        $this->ecriture('2026-02-12', [['622', 10, 0], ['571', 0, 10]], 'B', 'CA')->assertCreated()->assertJsonPath('numero', 'CA-2026-00002');
        $this->deleteJson("/api/comptabilite/ecritures/{$a}")->assertOk();
        $this->ecriture('2026-02-13', [['622', 10, 0], ['571', 0, 10]], 'C', 'CA')->assertCreated()->assertJsonPath('numero', 'CA-2026-00003');
    }

    public function test_filtres_export_et_protections(): void
    {
        $this->ecriture('2026-02-10', [['622', 300, 0], ['571', 0, 300]], 'Loyer de février')->assertCreated();
        $this->ecriture('2026-04-02', [['601', 1000, 0], ['401', 0, 1000]], 'Achat Brasserie', 'AC')->assertCreated();

        $this->assertSame(['AC-2026-00001'], array_column($this->getJson('/api/comptabilite/ecritures?compte_id='.$this->compte('601'))->json('data'), 'numero'));
        $this->assertSame(['OD-2026-00001'], array_column($this->getJson('/api/comptabilite/ecritures?recherche=loyer')->json('data'), 'numero'));
        $this->assertSame(['AC-2026-00001'], array_column($this->getJson('/api/comptabilite/ecritures?du=2026-03-01&au=2026-12-31')->json('data'), 'numero'));
        $this->assertSame(['AC-2026-00001', 'OD-2026-00001'], array_column($this->getJson('/api/comptabilite/ecritures')->json('data'), 'numero'));

        $csv = $this->get('/api/comptabilite/ecritures/export?du=2026-01-01&au=2026-12-31')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFdate;journal;numero;compte;libelle_compte;libelle;reference;debit;credit", $csv);
        $this->assertStringContainsString('2026-04-02;AC;AC-2026-00001;601;"Achats de marchandises";"Achat Brasserie";;1000,00;0,00', $csv);

        // Un compte qui a des écritures ne se supprime pas et ne devient pas un en-tête ; il peut se désactiver.
        $compte601 = $this->compte('601');
        $this->deleteJson("/api/comptabilite/comptes/{$compte601}")->assertUnprocessable()->assertJsonPath('message', 'Ce compte a des écritures : désactivez-le plutôt.');
        $champs = ['numero' => '601', 'libelle' => 'Achats de marchandises', 'nature' => 'charge', 'parent_id' => $this->compte('60')];
        $this->putJson("/api/comptabilite/comptes/{$compte601}", $champs + ['type' => 'en_tete'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson("/api/comptabilite/comptes/{$compte601}", $champs + ['type' => 'standard', 'actif' => false])->assertOk();

        // Un exercice qui a des écritures garde ses dates et ne se supprime pas.
        $this->putJson("/api/comptabilite/exercices/{$this->exercice['id']}", ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'trimestriel'])->assertUnprocessable();
        $this->deleteJson("/api/comptabilite/exercices/{$this->exercice['id']}")->assertUnprocessable();
    }

    public function test_droits_et_isolation(): void
    {
        $this->ecriture('2026-02-10', [['622', 300, 0], ['571', 0, 300]])->assertCreated();
        $autreCompte = $this->compte('571');

        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']), ['*']);
        $this->getJson('/api/comptabilite/ecritures')->assertOk()->assertJsonCount(1, 'data');
        $this->ecriture('2026-02-11', [['622', 10, 0], ['571', 0, 10]])->assertForbidden();

        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson('/api/comptabilite/ecritures')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/comptabilite/grand-livre?compte_id={$autreCompte}")->assertUnprocessable()->assertJsonValidationErrors('compte_id');
    }
}
