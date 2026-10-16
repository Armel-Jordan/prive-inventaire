<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\Approbation;
use App\Models\MouvementTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seuils d'approbation appliqués aux mouvements enregistrés par l'écran Relocalisation et par l'application mobile
 * (ils ne l'étaient que par POST /approbations, que personne n'appelle), et approbateur pris du compte connecté.
 */
class ApprobationsMouvementsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['nom' => 'Awa Ndiaye']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        $this->putJson('/api/approbations/settings', ['seuils' => [
            ['type_mouvement' => 'arrivage', 'seuil_quantite' => 100, 'actif' => true],
            ['type_mouvement' => 'transfert', 'seuil_quantite' => 50, 'actif' => true],
            ['type_mouvement' => 'sortie', 'seuil_quantite' => 50, 'actif' => false],
        ]])->assertOk();
    }

    private function compter(string $modele): int
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => $modele::count());
    }

    private function transfert(float $quantite, string $route = '/api/relocalisation'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($route, [
            'type' => 'transfert', 'produit_numero' => 'MEUB-28', 'secteur_source' => 'A1', 'secteur_destination' => 'B1',
            'quantite' => $quantite, 'employe' => 'EMP-007',
        ]);
    }

    public function test_un_transfert_au_dela_du_seuil_attend_une_approbation(): void
    {
        $this->transfert(5000)->assertStatus(202)
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('message', 'Quantité supérieure ou égale au seuil (50) : le mouvement attend une approbation.')
            ->assertJsonPath('approbation.demandeur', 'EMP-007')
            ->assertJsonPath('approbation.statut', 'en_attente');
        $this->assertSame(0, $this->compter(MouvementTenant::class));

        $this->transfert(10)->assertCreated();
        $this->assertSame(1, $this->compter(MouvementTenant::class));
    }

    public function test_le_seuil_s_applique_aussi_a_l_application_mobile_et_aux_lots(): void
    {
        $this->transfert(80, '/api/mobile/relocalisation')->assertStatus(202)->assertJsonPath('success', true);

        $this->postJson('/api/relocalisation/arrivage-lot', [
            'secteur_destination' => 'A1', 'employe' => 'EMP-007',
            'produits' => [['numero' => 'P-1', 'quantite' => 10], ['numero' => 'P-2', 'quantite' => 500]],
        ])->assertCreated()
            ->assertJsonCount(1, 'mouvements')
            ->assertJsonCount(1, 'approbations')
            ->assertJsonPath('message', "1 produit(s) ajouté(s) avec succès ; 1 en attente d'approbation (seuil atteint)");

        $this->assertSame(2, $this->compter(Approbation::class));
    }

    public function test_un_seuil_inactif_ou_un_ajustement_ne_demandent_pas_d_approbation(): void
    {
        $this->postJson('/api/relocalisation', [
            'type' => 'sortie', 'produit_numero' => 'P-1', 'secteur_source' => 'A1', 'quantite' => 900, 'employe' => 'EMP-007',
        ])->assertCreated();
        $this->postJson('/api/relocalisation', [
            'type' => 'ajustement', 'produit_numero' => 'P-1', 'quantite' => 900, 'employe' => 'EMP-007',
        ])->assertCreated();

        $this->assertSame(0, $this->compter(Approbation::class));
    }

    public function test_les_seuils_proposes_par_defaut_sont_inactifs(): void
    {
        $autre = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);

        $seuils = $this->getJson('/api/approbations/settings')->assertOk()->json();

        $this->assertCount(3, $seuils);
        $this->assertFalse(collect($seuils)->contains(fn ($s) => (bool) $s['actif']));
        $this->transfert(5000)->assertCreated();
    }

    public function test_l_approbateur_est_le_compte_connecte(): void
    {
        $premiere = $this->transfert(5000)->json('approbation.id');
        $seconde = $this->transfert(6000)->json('approbation.id');

        $this->postJson("/api/approbations/{$premiere}/approve", ['approbateur' => 'Le directeur général'])->assertOk()
            ->assertJsonPath('approbation.approbateur', 'Awa Ndiaye');
        $this->postJson("/api/approbations/{$seconde}/reject", ['commentaire' => 'Quantité erronée'])->assertOk()
            ->assertJsonPath('approbation.approbateur', 'Awa Ndiaye');

        $this->assertSame(1, $this->compter(MouvementTenant::class));
    }
}
