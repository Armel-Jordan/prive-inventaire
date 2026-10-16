<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Section Inventaire du tableau de bord : produits actifs, alertes et ruptures d'après les scans (comme l'écran
 * Alertes), secteurs et employés actifs, mouvements de relocalisation du mois. Elle lisait d'anciennes tables
 * vides et affichait « 0 alerte », « 0 mouvement », « 0 secteur », « 0 actif ».
 */
class TableauDeBordInventaireTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_compteurs_d_inventaire_refletent_les_donnees(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->runAsTenant($tenant->id, function () use ($tenant) {
            $secteurId = Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id;
            Secteur::create(['code' => 'B1', 'nom' => 'Allée 2', 'actif' => true]);
            Secteur::create(['code' => 'Z9', 'nom' => 'Fermée', 'actif' => false]);

            ProduitTenant::create(['numero' => 'BAS', 'description' => 'Stock bas', 'seuil_alerte' => 10, 'actif' => true, 'secteur_id' => $secteurId]);
            ProduitTenant::create(['numero' => 'VIDE', 'description' => 'Jamais scanné', 'seuil_alerte' => 5, 'actif' => true, 'secteur_id' => $secteurId]);
            ProduitTenant::create(['numero' => 'LIBRE', 'description' => 'Sans seuil', 'actif' => true, 'secteur_id' => $secteurId]);
            ProduitTenant::create(['numero' => 'ANCIEN', 'description' => 'Désactivé', 'seuil_alerte' => 5, 'actif' => false, 'secteur_id' => $secteurId]);
            ScanTenant::create(['numero' => 'BAS', 'quantite' => 4, 'unite_mesure' => 'UN', 'employe' => 'E1', 'secteur' => 'A1', 'date_saisie' => now()]);

            EmployeTenant::create(['tenant_id' => $tenant->id, 'numero' => 'E1', 'nom' => 'Ndiaye', 'actif' => true]);
            EmployeTenant::create(['tenant_id' => $tenant->id, 'numero' => 'E2', 'nom' => 'Mbarga', 'actif' => true]);
            EmployeTenant::create(['tenant_id' => $tenant->id, 'numero' => 'E3', 'nom' => 'Parti', 'actif' => false]);

            foreach ([['arrivage', '2026-10-05'], ['sortie', '2026-10-10'], ['transfert', '2026-10-12'], ['arrivage', '2026-09-20']] as [$type, $date]) {
                MouvementTenant::create([
                    'tenant_id' => $tenant->id, 'type' => $type, 'produit_numero' => 'BAS', 'quantite' => 1,
                    'employe' => 'E1', 'date_mouvement' => "{$date} 09:00:00",
                ]);
            }
        });
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)->getJson('/api/dashboard?period=month')->assertOk()
            ->assertJsonPath('inventaire.produits.total', 3)
            ->assertJsonPath('inventaire.produits.en_alerte', 2)
            ->assertJsonPath('inventaire.produits.rupture', 1)
            ->assertJsonPath('inventaire.secteurs', 2)
            ->assertJsonPath('inventaire.employes_actifs', 2)
            ->assertJsonPath('inventaire.mouvements.total_periode', 3)
            ->assertJsonPath('inventaire.mouvements.entrees', 1)
            ->assertJsonPath('inventaire.mouvements.sorties', 1);
    }
}
