<?php

namespace Tests\Feature\TachesPlanifiees;

use App\Models\AdminUser;
use App\Models\MouvementTenant;
use App\Models\Notification;
use App\Models\ProduitTenant;
use App\Models\TacheExecution;
use App\Models\TachePlanifiee;
use App\Models\Tenant;
use App\Models\TransfertPlanifie;
use App\Services\Planificateur;
use App\Support\TenantContext;
use App\Taches\SignalerAlertesStock;
use App\Taches\Tache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Tâches planifiées (plan de parité fripv2, phase 0.10) : inactives par défaut, échéances selon la
 * récurrence, exécution par le planificateur ou au passage des requêtes, historique, isolation.
 */
class TachesPlanifieesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache de fichiers (variables de la CI) : le verrou de passage laissé par un lancement précédent, à la même
        // heure figée, ne doit pas compter.
        Cache::flush();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', 'UTC'));
        $this->tenant = Tenant::factory()->create();
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant, string $role = 'admin'): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create(['role' => $role, 'nom' => 'Paul Mbarga']);
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function chez(Tenant $tenant, callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $action);
    }

    /** @return array<string, int> id de chaque tâche, par code */
    private function taches(): array
    {
        return collect($this->getJson('/api/taches-planifiees')->assertOk()->json('taches'))->pluck('id', 'code')->all();
    }

    private function transfert(array $donnees = []): TransfertPlanifie
    {
        return TransfertPlanifie::create($donnees + [
            'type' => 'transfert', 'produit_numero' => 'PRD-1', 'produit_nom' => 'Ciment', 'secteur_source' => 'A-01', 'secteur_destination' => 'B-02',
            'quantite' => 10, 'employe' => 'Awa', 'date_planifiee' => now()->subHour(), 'statut' => 'planifie',
        ]);
    }

    /** Rend une tâche due tout de suite. */
    private function rendreDue(int $id): void
    {
        TachePlanifiee::withoutTenantScope()->whereKey($id)->update(['actif' => true, 'prochaine_execution' => now()->subMinute()]);
    }

    public function test_les_taches_sont_inactives_par_defaut_et_reservees_aux_administrateurs(): void
    {
        $taches = $this->getJson('/api/taches-planifiees')->assertOk()->json('taches');

        $this->assertSame([
            'transferts_planifies', 'transferts_imminents', 'alertes_stock', 'nettoyage_notifications',
            'factures_impayees', 'relances_paiement', 'relances_devis', 'facturation_abonnements', 'facturation_locations', 'renouvellement_contrats',
            'commandes_fournisseur_en_retard', 'livraisons_en_retard', 'controles_techniques', 'ecritures_recurrentes', 'comptabilisation',
        ], array_column($taches, 'code'));
        // Inactives par défaut, y compris les relances et la facturation automatiques : rien ne part sans activation.
        $this->assertSame(array_fill(0, 15, false), array_column($taches, 'actif'));
        $this->assertSame(array_fill(0, 15, null), array_column($taches, 'prochaine_execution'));

        $this->connecter($this->tenant, 'user');
        $this->getJson('/api/taches-planifiees')->assertForbidden();
    }

    public function test_prochaine_echeance_selon_la_recurrence_et_le_fuseau(): void
    {
        $planificateur = app(Planificateur::class);
        $echeance = fn (array $reglage) => $planificateur->prochaine(new TachePlanifiee($reglage), now())?->format('Y-m-d H:i');

        // Jeudi 15 octobre 2026, 10 h 30 (UTC).
        $this->assertSame('2026-10-15 11:15', $echeance(['recurrence' => 'horaire', 'heure' => '00:15']));
        $this->assertSame('2026-10-16 07:00', $echeance(['recurrence' => 'quotidienne', 'heure' => '07:00']));
        $this->assertSame('2026-10-15 12:00', $echeance(['recurrence' => 'quotidienne', 'heure' => '12:00']));
        $this->assertSame('2026-10-19 08:00', $echeance(['recurrence' => 'hebdomadaire', 'heure' => '08:00', 'jours_semaine' => [1]]));
        $this->assertSame('2026-10-22 09:00', $echeance(['recurrence' => 'hebdomadaire', 'heure' => '09:00', 'jours_semaine' => [4]]));
        $this->assertSame('2026-10-31 02:00', $echeance(['recurrence' => 'mensuelle', 'heure' => '02:00', 'jour_du_mois' => 31]));
        $this->assertSame('2026-11-15 02:00', $echeance(['recurrence' => 'mensuelle', 'heure' => '02:00', 'jour_du_mois' => 15]));

        $this->travelTo(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
        $this->assertSame('2026-11-30 02:00', $echeance(['recurrence' => 'mensuelle', 'heure' => '02:00', 'jour_du_mois' => 31]));

        // 7 h à Douala (UTC+1) = 6 h UTC.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', 'UTC'));
        config(['taches.fuseau' => 'Africa/Douala']);
        $this->assertSame('2026-10-16 06:00', $echeance(['recurrence' => 'quotidienne', 'heure' => '07:00']));
    }

    public function test_reglage_d_une_tache_et_validation(): void
    {
        $id = $this->taches()['alertes_stock'];

        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => true, 'recurrence' => 'quotidienne', 'heure' => '07:00'])->assertOk()
            ->assertJsonPath('prochaine_execution', '2026-10-16T07:00:00+00:00');
        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => true, 'recurrence' => 'hebdomadaire', 'heure' => '07:00'])->assertUnprocessable()
            ->assertJsonValidationErrors(['jours_semaine' => 'Choisissez au moins un jour de la semaine.']);
        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => true, 'recurrence' => 'quotidienne', 'heure' => '25:00'])->assertUnprocessable()
            ->assertJsonValidationErrors('heure');
        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => true, 'recurrence' => 'une_fois', 'heure' => '07:00', 'prochaine_execution' => '2026-10-01 08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('prochaine_execution');
        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => true, 'recurrence' => 'une_fois', 'heure' => '07:00', 'prochaine_execution' => '2026-10-20 08:30'])
            ->assertOk()->assertJsonPath('prochaine_execution', '2026-10-20T08:30:00+00:00');
        $this->putJson("/api/taches-planifiees/{$id}", ['actif' => false, 'recurrence' => 'quotidienne', 'heure' => '07:00'])->assertOk()
            ->assertJsonPath('prochaine_execution', null);
    }

    public function test_le_planificateur_execute_les_transferts_arrives_a_echeance(): void
    {
        [$du, $futur] = $this->chez($this->tenant, fn () => [$this->transfert()->id, $this->transfert(['date_planifiee' => now()->addDay()])->id]);
        $id = $this->taches()['transferts_planifies'];
        $this->rendreDue($id);

        $this->artisan('taches:executer')->expectsOutput('1 tâche(s) exécutée(s).')->assertSuccessful();

        $this->chez($this->tenant, function () use ($du, $futur, $id) {
            $this->assertSame(['execute', 'Tâche planifiée'], [TransfertPlanifie::find($du)->statut, TransfertPlanifie::find($du)->execute_par]);
            $this->assertSame('planifie', TransfertPlanifie::find($futur)->statut);
            $this->assertSame(1, MouvementTenant::count());
            $this->assertSame(1, Notification::where('type', 'transfert_complete')->count());

            $execution = TacheExecution::firstOrFail();
            $this->assertSame(['planifie', 'succes', '1 transfert(s) exécuté(s).'], [$execution->mode, $execution->resultat, $execution->message]);
            // Horaire, minute 00 : prochaine échéance à 11 h.
            $this->assertSame('2026-10-15 11:00', TachePlanifiee::find($id)->prochaine_execution->format('Y-m-d H:i'));
        });
    }

    public function test_bouton_executer_un_transfert_repare(): void
    {
        $id = $this->chez($this->tenant, fn () => $this->transfert()->id);

        $this->postJson("/api/transferts-planifies/{$id}/execute")->assertOk()->assertJsonPath('transfert.statut', 'execute');
        $this->assertNotNull($this->chez($this->tenant, fn () => MouvementTenant::first()->date_mouvement));
    }

    public function test_alertes_de_stock_sans_doublon_tant_que_non_lues(): void
    {
        $this->chez($this->tenant, fn () => ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 10, 'seuil_alerte' => 5, 'actif' => true]));
        $id = $this->taches()['alertes_stock'];

        $this->postJson("/api/taches-planifiees/{$id}/executer")->assertOk()
            ->assertJsonPath('execution.mode', 'manuel')->assertJsonPath('execution.auteur', 'Paul Mbarga')
            ->assertJsonPath('execution.message', '1 produit(s) sous le seuil signalé(s).')
            ->assertJsonPath('tache.prochaine_execution', null);
        $this->postJson("/api/taches-planifiees/{$id}/executer")->assertOk()->assertJsonPath('execution.message', 'Aucune nouvelle alerte de stock.');

        $this->chez($this->tenant, fn () => Notification::query()->update(['lu' => true]));
        $this->postJson("/api/taches-planifiees/{$id}/executer")->assertOk();
        $this->assertSame(2, $this->chez($this->tenant, fn () => Notification::where('type', 'alerte_stock')->count()));
    }

    public function test_un_echec_est_consigne_et_une_tache_en_cours_n_est_pas_relancee(): void
    {
        $this->app->bind(SignalerAlertesStock::class, fn () => new class implements Tache
        {
            public function executer(): string
            {
                throw new RuntimeException('Base indisponible');
            }
        });
        $id = $this->taches()['alertes_stock'];

        $this->postJson("/api/taches-planifiees/{$id}/executer")->assertOk()
            ->assertJsonPath('execution.resultat', 'erreur')->assertJsonPath('execution.message', 'Base indisponible')
            ->assertJsonPath('tache.dernier_resultat', 'erreur');
        $this->getJson("/api/taches-planifiees/historique?tache_id={$id}&resultat=erreur")->assertOk()->assertJsonPath('total', 1);

        TachePlanifiee::withoutTenantScope()->whereKey($id)->update(['statut' => 'en_cours', 'debut_execution_courante' => now()]);
        $this->postJson("/api/taches-planifiees/{$id}/executer")->assertStatus(409);
    }

    public function test_chaque_entreprise_execute_ses_propres_taches(): void
    {
        $autre = Tenant::factory()->create();
        $this->chez($this->tenant, fn () => $this->transfert());
        $this->chez($autre, fn () => [$this->transfert(), $this->transfert()]);
        $this->rendreDue($this->taches()['transferts_planifies']);
        $this->connecter($autre);
        $this->rendreDue($this->taches()['transferts_planifies']);

        $this->assertSame(2, app(Planificateur::class)->executerTachesDues());

        $this->assertSame(1, $this->chez($this->tenant, fn () => Notification::count()));
        $this->assertSame(2, $this->chez($autre, fn () => Notification::count()));
        $this->getJson('/api/taches-planifiees/historique')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.message', '2 transfert(s) exécuté(s).');
    }

    public function test_execution_au_passage_des_requetes_au_plus_une_fois_par_minute(): void
    {
        $this->chez($this->tenant, fn () => $this->transfert());
        $id = $this->taches()['transferts_planifies'];
        $this->rendreDue($id);
        TachePlanifiee::withoutTenantScope()->whereKey($id)->update(['recurrence' => 'une_fois']);

        $planificateur = app(Planificateur::class);
        $this->assertSame(1, $planificateur->auPassage());
        $this->assertSame(0, $planificateur->auPassage());
        $this->assertSame('requetes', Cache::get(Planificateur::CLE_DERNIER_PASSAGE)['source']);

        // Une exécution unique se désactive ensuite.
        $tache = TachePlanifiee::withoutTenantScope()->find($id);
        $this->assertFalse($tache->actif);
        $this->assertNull($tache->prochaine_execution);
    }

    public function test_planifiee_chaque_minute(): void
    {
        $taches = collect(app(Schedule::class)->events())->filter(fn ($tache) => str_contains((string) $tache->command, 'taches:executer'));

        $this->assertCount(1, $taches);
        $this->assertSame('* * * * *', $taches->first()->expression);
    }
}
