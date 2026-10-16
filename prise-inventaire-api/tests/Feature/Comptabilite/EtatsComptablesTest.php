<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Soldes d'ouverture, balance et grand livre (phase 4.2). */
class EtatsComptablesTest extends TestCase
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

    private function ouverture(array $soldes): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/comptabilite/soldes-ouverture', ['lignes' => array_map(
            fn ($numero, $montants) => ['compte_id' => $this->compte($numero), 'debit' => $montants[0], 'credit' => $montants[1]],
            array_keys($soldes), $soldes,
        )]);
    }

    private function ecriture(string $date, array $lignes, string $libelle): void
    {
        $journal = collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', 'OD')['id'];
        $this->postJson('/api/comptabilite/ecritures', [
            'journal_id' => $journal, 'date' => $date, 'libelle' => $libelle,
            'lignes' => array_map(fn ($l) => ['compte_id' => $this->compte($l[0]), 'debit' => $l[1], 'credit' => $l[2]], $lignes),
        ])->assertCreated();
    }

    public function test_soldes_d_ouverture(): void
    {
        $etat = $this->getJson('/api/comptabilite/soldes-ouverture')->assertOk()->assertJsonPath('exercice.libelle', '2026')->assertJsonPath('ecriture', null)->json();
        $numeros = array_column($etat['comptes'], 'numero');
        $this->assertContains('521', $numeros);
        $this->assertNotContains('601', $numeros);
        $this->assertNotContains('52', $numeros);

        $this->ouverture(['521' => [500000, 0], '101' => [0, 400000]])->assertUnprocessable()
            ->assertJsonPath('message', 'L\'écriture n\'est pas équilibrée : débit 500 000,00, crédit 400 000,00 (écart 100 000,00).');
        $this->ouverture(['601' => [100, 0], '101' => [0, 100]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.compte_id');

        $this->ouverture(['521' => [500000, 0], '101' => [0, 400000], '162' => [0, 100000], '411' => [0, 0]])->assertOk()
            ->assertJsonPath('ecriture.numero', 'AN-2026-00001');
        $comptes = collect($this->getJson('/api/comptabilite/soldes-ouverture')->json('comptes'))->keyBy('numero');
        $this->assertSame([500000, 0], [$comptes['521']['debit'], $comptes['521']['credit']]);
        $this->assertSame(100000, $comptes['162']['credit']);

        // Une nouvelle saisie remplace l'écriture (même numéro).
        $this->ouverture(['521' => [450000, 0], '571' => [50000, 0], '101' => [0, 500000]])->assertOk()->assertJsonPath('ecriture.numero', 'AN-2026-00001');
        $this->assertSame(['521', '571', '101'], array_column($this->getJson('/api/comptabilite/ecritures?source=ouverture')->json('data.0.lignes'), 'compte'));
        // Le journal ne la modifie pas : elle se règle depuis son écran.
        $id = $this->getJson('/api/comptabilite/ecritures?source=ouverture')->json('data.0.id');
        $this->deleteJson("/api/comptabilite/ecritures/{$id}")->assertUnprocessable()->assertJsonPath('message', 'Les soldes d\'ouverture se modifient depuis leur écran.');

        $premiere = $this->exercice['periodes'][0]['id'];
        $this->postJson("/api/comptabilite/periodes/{$premiere}/fermer")->assertOk();
        $this->getJson('/api/comptabilite/soldes-ouverture')->assertJsonPath('exercice.verrouille', true);
        $this->ouverture(['521' => [1, 0], '101' => [0, 1]])->assertUnprocessable();
        $this->postJson("/api/comptabilite/periodes/{$premiere}/rouvrir")->assertOk();
        $this->putJson('/api/comptabilite/soldes-ouverture', ['lignes' => []])->assertOk()->assertJsonPath('ecriture', null);
        $this->getJson('/api/comptabilite/ecritures?source=ouverture')->assertJsonCount(0, 'data');
    }

    public function test_balance_et_grand_livre(): void
    {
        $this->ouverture(['521' => [500000, 0], '101' => [0, 500000]])->assertOk();
        $this->ecriture('2026-02-10', [['601', 100000, 0], ['445', 19250, 0], ['401', 0, 119250]], 'Achat de marchandises');
        $this->ecriture('2026-03-05', [['401', 119250, 0], ['521', 0, 119250]], 'Règlement fournisseur');
        $this->ecriture('2026-04-01', [['411', 238500, 0], ['701', 0, 200000], ['443', 0, 38500]], 'Vente');

        // Exercice entier (dates par défaut) : l'écriture d'ouverture du 1er janvier est un mouvement de la période.
        $balance = $this->getJson('/api/comptabilite/balance')->assertOk()->assertJsonPath('du', '2026-01-01')->assertJsonPath('au', '2026-12-31')
            ->assertJsonPath('equilibree', true)->json();
        $comptes = collect($balance['comptes'])->keyBy('numero');
        $this->assertSame(['521', 0, 500000, 119250, 380750], [$comptes['521']['numero'], $comptes['521']['ouverture_debit'], $comptes['521']['mouvement_debit'], $comptes['521']['mouvement_credit'], $comptes['521']['solde_debit']]);
        $this->assertSame(0, $comptes['401']['solde_credit']);
        $this->assertSame($balance['totaux']['mouvement_debit'], $balance['totaux']['mouvement_credit']);
        $this->assertSame(977000, $balance['totaux']['mouvement_debit']);

        // À partir de mars : ce qui précède devient le solde d'ouverture.
        $mars = collect($this->getJson('/api/comptabilite/balance?du=2026-03-01&au=2026-12-31')->assertJsonPath('equilibree', true)->json('comptes'))->keyBy('numero');
        $this->assertSame([500000, 119250, 380750], [$mars['521']['ouverture_debit'], $mars['521']['mouvement_credit'], $mars['521']['solde_debit']]);
        $this->assertSame([119250, 119250, 0], [$mars['401']['ouverture_credit'], $mars['401']['mouvement_debit'], $mars['401']['solde_credit']]);

        $csv = $this->get('/api/comptabilite/balance?du=2026-01-01&au=2026-12-31&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('521;"Banques locales";0,00;0,00;500000,00;119250,00;380750,00;0,00', $csv);
        $this->assertStringContainsString(';Totaux;0,00;0,00;977000,00;977000,00;', $csv);

        // Grand livre d'un en-tête : il cumule ses sous-comptes, avec un solde progressif.
        $livre = $this->getJson('/api/comptabilite/grand-livre?compte_id='.$this->compte('52').'&du=2026-02-01&au=2026-12-31')->assertOk()->json();
        $this->assertSame([500000, 380750], [$livre['solde_ouverture'], $livre['solde_final']]);
        $this->assertSame([['2026-03-05', 'OD-2026-00002', '521', 0, 119250, 380750]], array_map(fn ($l) => [$l['date'], $l['numero'], $l['compte'], $l['debit'], $l['credit'], $l['solde']], $livre['lignes']));

        $fournisseur = $this->getJson('/api/comptabilite/grand-livre?compte_id='.$this->compte('401'))->assertOk()->json();
        $this->assertSame([-119250, 0], array_column($fournisseur['lignes'], 'solde'));
        $this->assertSame('Règlement fournisseur', $fournisseur['lignes'][1]['libelle']);
        $this->assertSame(['OD-2026-00001'], array_column($this->getJson('/api/comptabilite/grand-livre?compte_id='.$this->compte('401').'&recherche=achat')->json('lignes'), 'numero'));

        $csv = $this->get('/api/comptabilite/grand-livre?compte_id='.$this->compte('401').'&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('2026-03-05;OD;OD-2026-00002;401;"Règlement fournisseur";;119250,00;0,00;0,00', $csv);
    }
}
