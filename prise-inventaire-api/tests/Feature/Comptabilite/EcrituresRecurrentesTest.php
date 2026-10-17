<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Écritures récurrentes (phase 4.2) : répétition planifiée d'une écriture manuelle (dates selon la fréquence, lignes
 * copiées), conversion des dates échues (refus d'une période fermée, puis reprise), numéro de récurrence au journal,
 * dates choisies, suspension, suppression, tâche planifiée ; contrôles, droits et isolation.
 */
class EcrituresRecurrentesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private array $exercice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-15 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->json();
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function compte(string $numero): int
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    private function journal(string $code): int
    {
        return collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', $code)['id'];
    }

    /** Loyer : 622 au débit, banque 521 au crédit. */
    private function loyer(string $date, float $montant = 500): array
    {
        return $this->postJson('/api/comptabilite/ecritures', [
            'journal_id' => $this->journal('OD'), 'date' => $date, 'libelle' => 'Loyer entrepôt', 'reference' => 'BAIL-12',
            'lignes' => [['compte_id' => $this->compte('622'), 'debit' => $montant], ['compte_id' => $this->compte('521'), 'credit' => $montant]],
        ])->assertCreated()->json();
    }

    private function repeter(int $ecriture, string $debut, int $nombre, string $frequence): TestResponse
    {
        return $this->postJson("/api/comptabilite/ecritures/{$ecriture}/recurrence", ['date_debut' => $debut, 'nombre' => $nombre, 'frequence' => $frequence]);
    }

    private function dates(array $recurrence): array
    {
        return array_column($recurrence['echeances'], 'date_prevue');
    }

    public function test_planifier_convertir_et_suivre_une_recurrence(): void
    {
        $modele = $this->loyer('2026-01-31');
        // Du 31 janvier, chaque mois : le dernier jour des mois plus courts, sans glisser ensuite.
        $recurrence = $this->repeter($modele['id'], '2026-01-31', 4, 'mensuelle')->assertCreated()
            ->assertJsonPath('numero', 1)->assertJsonPath('journal.code', 'OD')->assertJsonPath('modele.numero', 'OD-2026-00001')
            ->assertJsonPath('echues', 2)->assertJsonPath('prochaine', '2026-01-31')->json();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $this->dates($recurrence));
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonPath('echues', 2)->assertJsonPath('recurrences.0.numero', 1);

        // Le modèle change ensuite : la récurrence garde les lignes copiées.
        $this->putJson("/api/comptabilite/ecritures/{$modele['id']}", [
            'journal_id' => $this->journal('OD'), 'date' => '2026-01-31', 'libelle' => 'Loyer entrepôt',
            'lignes' => [['compte_id' => $this->compte('622'), 'debit' => 600], ['compte_id' => $this->compte('521'), 'credit' => 600]],
        ])->assertOk();

        // Février fermé : janvier se convertit, février reste à convertir avec son motif.
        $this->postJson('/api/comptabilite/periodes/'.$this->exercice['periodes'][1]['id'].'/fermer')->assertOk();
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()
            ->assertJsonPath('converties', 1)->assertJsonPath('message', '1 écriture(s) récurrente(s) créée(s).')
            ->assertJsonPath('erreurs', [['recurrence' => 1, 'date' => '2026-02-28', 'message' => 'La période du 01/02/2026 au 28/02/2026 est fermée.']]);
        $this->postJson('/api/comptabilite/periodes/'.$this->exercice['periodes'][1]['id'].'/rouvrir')->assertOk();
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()->assertJsonPath('converties', 1)->assertJsonPath('erreurs', []);

        // Au journal : la source « recurrente », le numéro de récurrence, les lignes copiées (500).
        $ecritures = $this->getJson('/api/comptabilite/ecritures?recurrence=1')->assertOk()->json('data');
        $this->assertSame([['2026-02-28', 'recurrente', 1, 500.0], ['2026-01-31', 'recurrente', 1, 500.0]],
            array_map(fn (array $e) => [$e['date'], $e['source'], $e['recurrence'], (float) $e['total']], $ecritures));
        $this->assertSame(['OD-2026-00002', 'BAIL-12'], [$ecritures[1]['numero'], $ecritures[1]['reference']]);

        // Plus tard, les dates de mars et d'avril ; puis plus rien.
        $this->travelTo('2026-05-01 08:00:00');
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()->assertJsonPath('converties', 2);
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()->assertJsonPath('converties', 0)->assertJsonPath('message', 'Aucune écriture à créer.');
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonPath('recurrences.0.converties', 4)->assertJsonPath('recurrences.0.prochaine', null);

        // Récurrence suspendue : ses dates échues attendent ; dates choisies seulement.
        $autre = $this->repeter($this->loyer('2026-04-30', 80)['id'], '2026-04-01', 3, 'hebdomadaire')->assertCreated()->json();
        $this->putJson("/api/comptabilite/recurrences/{$autre['id']}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()->assertJsonPath('converties', 0);
        $this->putJson("/api/comptabilite/recurrences/{$autre['id']}", ['active' => true])->assertOk();
        $this->postJson('/api/comptabilite/recurrences/convertir', ['echeances' => [$autre['echeances'][1]['id']]])->assertOk()->assertJsonPath('converties', 1);
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonPath('recurrences.0.converties', 1)->assertJsonPath('recurrences.0.echues', 2);

        // La tâche planifiée convertit le reste ; supprimer une récurrence laisse ses écritures.
        $tache = collect($this->getJson('/api/taches-planifiees')->assertOk()->json('taches'))->firstWhere('code', 'ecritures_recurrentes');
        $this->postJson("/api/taches-planifiees/{$tache['id']}/executer")->assertOk()
            ->assertJsonPath('execution.message', '2 écriture(s) récurrente(s) créée(s).');
        $this->deleteJson("/api/comptabilite/recurrences/{$autre['id']}")->assertOk()
            ->assertJsonPath('message', 'Récurrence n° 2 supprimée : ses écritures déjà créées restent au journal.');
        $this->assertCount(3, $this->getJson('/api/comptabilite/ecritures?recurrence=2')->assertOk()->json('data'));
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonPath('recurrences.0.active', false)->assertJsonPath('recurrences.0.prochaine', null);

        // Sans écriture créée, la récurrence disparaît.
        $vide = $this->repeter($modele['id'], '2026-12-31', 2, 'mensuelle')->assertCreated()->json();
        $this->deleteJson("/api/comptabilite/recurrences/{$vide['id']}")->assertOk();
        $this->assertSame([2, 1], array_column($this->getJson('/api/comptabilite/recurrences')->json('recurrences'), 'numero'));
    }

    public function test_frequences_controles_droits_et_isolation(): void
    {
        $modele = $this->loyer('2026-01-31');
        foreach ([
            ['journaliere', '2026-03-30', 3, ['2026-03-30', '2026-03-31', '2026-04-01']],
            ['hebdomadaire', '2026-03-02', 3, ['2026-03-02', '2026-03-09', '2026-03-16']],
            ['quatorze_jours', '2026-03-02', 3, ['2026-03-02', '2026-03-16', '2026-03-30']],
            ['trimestrielle', '2026-01-31', 3, ['2026-01-31', '2026-04-30', '2026-07-31']],
            ['semestrielle', '2026-01-31', 2, ['2026-01-31', '2026-07-31']],
            ['annuelle', '2026-02-28', 2, ['2026-02-28', '2027-02-28']],
        ] as [$frequence, $debut, $nombre, $attendues]) {
            $this->assertSame($attendues, $this->dates($this->repeter($modele['id'], $debut, $nombre, $frequence)->assertCreated()->json()), $frequence);
        }

        // Seule une écriture saisie à la main se répète (pas une contre-passation ni une écriture récurrente).
        $inverse = $this->postJson("/api/comptabilite/ecritures/{$modele['id']}/contre-passer", ['date' => '2026-03-15'])->assertCreated()->json();
        $this->repeter($inverse['id'], '2026-04-01', 2, 'mensuelle')->assertUnprocessable()->assertJsonValidationErrors('ecriture');
        $this->postJson('/api/comptabilite/recurrences/convertir', ['jusqu_au' => '2026-03-02'])->assertOk();
        $recurrente = $this->getJson('/api/comptabilite/ecritures?source=recurrente')->json('data.0');
        $this->repeter($recurrente['id'], '2026-04-01', 2, 'mensuelle')->assertUnprocessable()->assertJsonValidationErrors('ecriture');
        $this->repeter($modele['id'], '2026-04-01', 0, 'mensuelle')->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->repeter($modele['id'], '2026-04-01', 121, 'mensuelle')->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->repeter($modele['id'], '2026-04-01', 2, 'bimensuelle')->assertUnprocessable()->assertJsonValidationErrors('frequence');

        // Une comptable qui consulte sans saisir d'écritures.
        $lectrice = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$lectrice->id}", ['groupes' => [], 'exceptions' => ['comptabilite.voir' => true, 'comptabilite.ecrire' => false]])->assertOk();
        $this->agir($lectrice);
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonCount(6, 'recurrences');
        $this->repeter($modele['id'], '2026-04-01', 2, 'mensuelle')->assertForbidden();
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertForbidden();

        // Une autre entreprise ne voit ni ne touche ces récurrences.
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson('/api/comptabilite/recurrences')->assertOk()->assertJsonCount(0, 'recurrences')->assertJsonPath('echues', 0);
        $this->repeter($modele['id'], '2026-04-01', 2, 'mensuelle')->assertNotFound();
        $this->postJson('/api/comptabilite/recurrences/convertir')->assertOk()->assertJsonPath('converties', 0);
    }
}
