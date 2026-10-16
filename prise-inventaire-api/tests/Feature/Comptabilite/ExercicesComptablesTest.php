<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercices comptables (phase 4.1) : découpage en périodes, chevauchements, modification, fermeture et
 * réouverture des périodes.
 */
class ExercicesComptablesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->connecter('admin');
    }

    private function connecter(string $role): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => $role]), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    public function test_decoupage_mensuel_trimestriel_et_unique(): void
    {
        $exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])
            ->assertCreated()->assertJsonPath('libelle', '2026')->assertJsonPath('decoupage', 'mensuel')->json();
        $this->assertCount(12, $exercice['periodes']);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$exercice['periodes'][1]['date_debut'], $exercice['periodes'][1]['date_fin']]);
        $this->assertSame([10, true], [$exercice['periodes'][9]['numero'], $exercice['periodes'][9]['courante']]);

        // Exercice décalé, commencé en cours de mois : premier mois partiel, puis trimestres.
        $decale = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-01-15', 'date_fin' => '2027-12-31', 'decoupage' => 'trimestriel'])
            ->assertCreated()->json();
        $this->assertSame([
            ['2027-01-15', '2027-03-31'], ['2027-04-01', '2027-06-30'], ['2027-07-01', '2027-09-30'], ['2027-10-01', '2027-12-31'],
        ], array_map(fn ($p) => [$p['date_debut'], $p['date_fin']], $decale['periodes']));

        $long = $this->postJson('/api/comptabilite/exercices', ['libelle' => 'Premier exercice', 'date_debut' => '2028-01-01', 'date_fin' => '2029-06-30', 'decoupage' => 'unique'])
            ->assertCreated()->json();
        $this->assertSame([['2028-01-01', '2029-06-30']], array_map(fn ($p) => [$p['date_debut'], $p['date_fin']], $long['periodes']));

        $this->assertSame(['Premier exercice', '2027', '2026'], array_column($this->getJson('/api/comptabilite/exercices')->assertOk()->json('exercices'), 'libelle'));
    }

    public function test_dates_refusees(): void
    {
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertCreated();

        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-12-01', 'date_fin' => '2027-11-30', 'decoupage' => 'mensuel'])
            ->assertUnprocessable()->assertJsonPath('message', 'Ces dates chevauchent l\'exercice 2026 (01/01/2026 – 31/12/2026).');
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-06-01', 'date_fin' => '2027-01-01', 'decoupage' => 'mensuel'])->assertUnprocessable();
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-01-01', 'date_fin' => '2029-01-01', 'decoupage' => 'mensuel'])
            ->assertUnprocessable()->assertJsonPath('message', 'Un exercice ne peut pas dépasser 24 mois.');
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2027-01-01', 'date_fin' => '2027-12-31', 'decoupage' => 'hebdo'])
            ->assertUnprocessable()->assertJsonValidationErrors('decoupage');
    }

    public function test_modification_fermeture_et_reouverture(): void
    {
        $exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->json();

        // Tant qu'aucune période n'est fermée, l'exercice se redécoupe.
        $modifie = $this->putJson("/api/comptabilite/exercices/{$exercice['id']}", ['libelle' => 'Année 2026', 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'trimestriel'])
            ->assertOk()->assertJsonPath('libelle', 'Année 2026')->assertJsonPath('decoupage', 'trimestriel')->json();
        $this->assertCount(4, $modifie['periodes']);

        $premiere = $modifie['periodes'][0]['id'];
        $this->postJson("/api/comptabilite/periodes/{$premiere}/fermer")->assertOk()->assertJsonPath('fermee', true);
        $this->postJson("/api/comptabilite/periodes/{$premiere}/fermer")->assertUnprocessable();

        // Une période fermée fige les dates et le découpage, et empêche la suppression ; le libellé reste modifiable.
        $this->putJson("/api/comptabilite/exercices/{$exercice['id']}", ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertUnprocessable();
        $this->putJson("/api/comptabilite/exercices/{$exercice['id']}", ['libelle' => '2026', 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'trimestriel'])->assertOk();
        $this->deleteJson("/api/comptabilite/exercices/{$exercice['id']}")->assertUnprocessable();

        // Réouverture : administrateurs seulement.
        $this->connecter('manager');
        $this->postJson("/api/comptabilite/periodes/{$premiere}/rouvrir")->assertForbidden();
        $this->connecter('admin');
        $this->postJson("/api/comptabilite/periodes/{$premiere}/rouvrir")->assertOk()->assertJsonPath('fermee', false);
        $this->deleteJson("/api/comptabilite/exercices/{$exercice['id']}")->assertOk();
        $this->getJson('/api/comptabilite/exercices')->assertOk()->assertJsonCount(0, 'exercices');
    }
}
