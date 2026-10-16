<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Journal des modifications (écran Historique) alimenté par les créations, modifications et suppressions des
 * fiches et documents : il restait vide, rien ne l'écrivait.
 */
class JournalModificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['nom' => 'Awa Ndiaye']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function journal(): \Illuminate\Support\Collection
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => AuditLog::orderBy('id')->get());
    }

    public function test_creation_modification_et_suppression_d_un_secteur_sont_journalisees(): void
    {
        $id = $this->postJson('/api/secteurs', ['code' => 'A1', 'nom' => 'Allée 1'])->assertCreated()->json('secteur.id');
        $this->putJson("/api/secteurs/{$id}", ['nom' => 'Allée principale'])->assertOk();
        $this->deleteJson("/api/secteurs/{$id}")->assertOk();

        $journal = $this->journal();
        $this->assertSame(['create', 'update'], $journal->pluck('action')->take(2)->all());
        $this->assertTrue($journal->every(fn ($l) => $l->model_type === 'Secteur' && $l->model_id === (string) $id && $l->user_name === 'Awa Ndiaye'));
        $this->assertSame('Allée 1', $journal[0]->new_values['nom']);
        $this->assertSame(['nom' => 'Allée 1'], $journal[1]->old_values);
        $this->assertSame(['nom' => 'Allée principale'], $journal[1]->new_values);
    }

    public function test_le_mot_de_passe_d_un_compte_n_est_jamais_journalise(): void
    {
        $this->postJson('/api/employes', [
            'numero' => 'E-1', 'nom' => 'Mbarga', 'email' => 'mbarga@alpha.test', 'role' => 'user', 'password' => 'secret123',
        ])->assertCreated();

        $compte = $this->journal()->firstWhere('model_type', 'Compte utilisateur');
        $this->assertNotNull($compte);
        $this->assertSame('mbarga@alpha.test', $compte->new_values['email']);
        $this->assertArrayNotHasKey('password', $compte->new_values);
        $this->assertNotNull($this->journal()->firstWhere('model_type', 'Employé'));
    }

    public function test_un_scan_ne_journalise_que_ses_corrections_et_sa_suppression(): void
    {
        $id = $this->postJson('/api/scan/enregistrer', ['numero' => 'P-1', 'quantite' => 3, 'employe' => 'E1', 'secteur' => 'A1'])
            ->assertCreated()->json('scan.id');
        $this->assertCount(0, $this->journal());

        $this->putJson("/api/scan/{$id}", ['quantite' => 4])->assertOk();
        $this->deleteJson("/api/scan/{$id}")->assertOk();

        $journal = $this->journal();
        $this->assertSame(['update', 'delete'], $journal->pluck('action')->all());
        $this->assertEquals(4, $journal[0]->new_values['quantite']);
        $this->assertSame('P-1', $journal[1]->old_values['numero']);
    }

    public function test_le_journal_est_lisible_dans_l_ecran_historique_et_propre_a_l_entreprise(): void
    {
        $this->postJson('/api/secteurs', ['code' => 'A1', 'nom' => 'Allée 1'])->assertCreated();
        $autre = Tenant::factory()->create();
        app(TenantContext::class)->runAsTenant($autre->id, fn () => Secteur::create(['code' => 'Z9', 'nom' => 'Autre']));

        $this->getJson('/api/audit')->assertOk()->assertJsonCount(1)->assertJsonPath('0.model_type', 'Secteur');
        $this->getJson('/api/audit/stats')->assertOk();
    }

    public function test_rien_n_est_journalise_hors_d_un_contexte_d_entreprise(): void
    {
        AdminUser::factory()->for($this->tenant)->create();

        $this->assertCount(0, $this->journal()->where('model_type', 'Compte utilisateur'));
    }
}
