<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Corrections d'écrans existants qui échouaient systématiquement :
 * - réception de marchandises (mise à jour d'une colonne « quantite_stock » inexistante) ;
 * - approbation d'un mouvement (date_mouvement obligatoire non renseignée) ;
 * - prévisions de stock (colonnes « numero » et « action » inexistantes).
 */
class ReceptionsApprobationsPrevisionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    public function test_une_reception_s_enregistre_et_fait_avancer_la_commande(): void
    {
        [$commande, $ligne] = $this->chez(function () {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Fournisseur Test']);
            $produit = ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100]);
            $commande = ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'montant_total' => 600, 'created_by' => $this->admin->id,
            ]);
            $ligne = ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => $produit->id, 'quantite_commandee' => 6,
                'quantite_recue' => 0, 'prix_unitaire' => 100, 'montant_ligne' => 600,
            ]);

            return [$commande, $ligne];
        });

        $this->postJson('/api/receptions', ['com_four_ligne_id' => $ligne->id, 'date_reception' => '2026-10-16', 'quantite_recue' => 4])->assertCreated();
        $this->assertSame(4, (int) $ligne->fresh()->quantite_recue);
        $this->assertSame(ComFourEntete::STATUT_PARTIELLE, $this->chez(fn () => $commande->fresh()->statut));

        $this->postJson('/api/receptions', ['com_four_ligne_id' => $ligne->id, 'date_reception' => '2026-10-16', 'quantite_recue' => 2])->assertCreated();
        $this->assertSame(ComFourEntete::STATUT_COMPLETE, $this->chez(fn () => $commande->fresh()->statut));
    }

    public function test_une_demande_d_approbation_s_approuve_et_cree_le_mouvement(): void
    {
        DB::table('parametres_approbation')->insert([
            'tenant_id' => $this->tenant->id, 'type_mouvement' => 'sortie', 'seuil_quantite' => 100, 'actif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Au-dessus du seuil : demande en attente, puis approuvée.
        $demande = $this->postJson('/api/approbations', ['type_mouvement' => 'sortie', 'produit_numero' => 'CIM-50', 'quantite' => 150, 'demandeur' => 'Paul'])
            ->assertCreated()->assertJson(['requires_approval' => true])->json('approbation');
        $this->postJson("/api/approbations/{$demande['id']}/approve", ['approbateur' => 'Chef de dépôt'])->assertOk();

        // Sous le seuil : mouvement direct.
        $this->postJson('/api/approbations', ['type_mouvement' => 'sortie', 'produit_numero' => 'CIM-50', 'quantite' => 5, 'demandeur' => 'Paul'])
            ->assertCreated()->assertJson(['requires_approval' => false]);

        $mouvements = $this->chez(fn () => MouvementTenant::where('produit_numero', 'CIM-50')->orderBy('quantite')->get());
        $this->assertSame([5.0, 150.0], $mouvements->pluck('quantite')->map(fn ($q) => (float) $q)->all());
        $this->assertTrue($mouvements->every(fn ($m) => $m->date_mouvement !== null));
    }

    public function test_les_previsions_de_stock_se_calculent_sur_les_sorties_des_30_derniers_jours(): void
    {
        $this->chez(function () {
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'seuil_alerte' => 10, 'actif' => true]);
            ScanTenant::create(['numero' => 'CIM-50', 'quantite' => 40, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => now()]);
            $mouvement = fn (string $type, float $quantite, int $jours) => MouvementTenant::create([
                'type' => $type, 'produit_numero' => 'CIM-50', 'quantite' => $quantite, 'employe' => 'Paul', 'date_mouvement' => now()->subDays($jours),
            ]);
            $mouvement(MouvementTenant::TYPE_SORTIE, 30, 5);
            // Hors calcul : sortie trop ancienne, et arrivage.
            $mouvement(MouvementTenant::TYPE_SORTIE, 300, 40);
            $mouvement(MouvementTenant::TYPE_ARRIVAGE, 100, 2);
        });

        $ligne = collect($this->getJson('/api/alertes/previsions')->assertOk()->json('produits'))->firstWhere('numero', 'CIM-50');

        $this->assertEquals(40, $ligne['stock_actuel']);
        $this->assertEquals(1, $ligne['consommation_jour']);
        $this->assertSame(40, $ligne['jours_restants']);
    }

    public function test_les_compteurs_d_alertes_suivent_les_niveaux_de_la_liste(): void
    {
        $this->chez(function () {
            // Seuil 100 : critique jusqu'à 25 %, avertissement jusqu'à 50 %, information au-delà (sous le seuil).
            foreach (['CRIT' => 20, 'AVERT' => 40, 'INFO' => 80, 'OK' => 150] as $numero => $stock) {
                ProduitTenant::create(['numero' => $numero, 'description' => $numero, 'seuil_alerte' => 100, 'actif' => true]);
                ScanTenant::create(['numero' => $numero, 'quantite' => $stock, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => now()]);
            }
        });

        $this->getJson('/api/alertes/stats')->assertOk()->assertJson([
            'total_produits_surveilles' => 4, 'en_alerte' => 3, 'critiques' => 1, 'warnings' => 1, 'informations' => 1, 'ok' => 1,
        ]);
        // La liste (que l'écran filtre par niveau) compte ses avertissements comme le compteur.
        $liste = $this->getJson('/api/alertes')->assertOk()->json();
        $this->assertSame([3, 1, 1], [$liste['total'], $liste['critiques'], $liste['warnings']]);
        $this->assertSame(['CRIT', 'AVERT', 'INFO'], array_column($liste['alertes'], 'numero'));
    }
}
