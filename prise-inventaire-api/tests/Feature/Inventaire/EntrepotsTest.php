<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\Entrepot;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\EntreprisePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-entrepôts (phase 2.5) : les secteurs se rangent dans les entrepôts du référentiel (ceux sans entrepôt restent
 * valides) ; stock par entrepôt d'après les scans (secteur reconnu par son code ou son nom) ; filtres des scans et de
 * la valorisation ; « Livrer à » des commandes fournisseur ; droits et isolation.
 */
class EntrepotsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $douala;

    private int $yaounde;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 08:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        $this->douala = $this->postJson('/api/referentiels/entrepots', ['code' => 'DLA', 'nom' => 'Entrepôt de Douala', 'type' => 'entrepot',
            'adresse' => 'Zone portuaire, rue 12', 'ville' => 'Douala', 'pays' => 'Cameroun', 'par_defaut' => true, 'actif' => true])->assertCreated()->json('id');
        $this->yaounde = $this->chez(fn () => Entrepot::create(['code' => 'YDE', 'nom' => 'Magasin de Yaoundé', 'type' => 'magasin', 'actif' => true])->id);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function scan(string $numero, float $quantite, string $secteur): void
    {
        $this->chez(fn () => ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => $secteur, 'date_saisie' => now()]));
    }

    public function test_secteurs_stock_et_valorisation_par_entrepot(): void
    {
        // Secteurs : deux rangés dans un entrepôt, un sans entrepôt (il reste valide).
        $a1 = $this->postJson('/api/secteurs', ['code' => 'A1', 'nom' => 'Allée 1', 'entrepot_id' => $this->douala])->assertCreated()
            ->assertJsonPath('secteur.entrepot.code', 'DLA')->json('secteur.id');
        $b1 = $this->postJson('/api/secteurs', ['code' => 'B1', 'nom' => 'Rayon B', 'entrepot_id' => $this->yaounde])->assertCreated()->json('secteur.id');
        $c1 = $this->postJson('/api/secteurs', ['code' => 'C1', 'nom' => 'Cour'])->assertCreated()->assertJsonPath('secteur.entrepot', null)->json('secteur.id');
        $this->assertSame(['A1'], array_column($this->getJson("/api/secteurs?entrepot_id={$this->douala}")->assertOk()->json(), 'code'));
        $this->assertSame('DLA', collect($this->getJson('/api/secteurs')->json())->firstWhere('code', 'A1')['entrepot']['code']);
        // Prise d'inventaire des trois secteurs, ouverte avant les scans (valorisation plus bas).
        $prise = $this->postJson('/api/prises-inventaire', ['libelle' => 'Inventaire', 'secteur_ids' => [$a1, $b1, $c1]])->assertCreated()->json('id');
        $this->travelTo('2026-10-17 09:00:00');

        // Stock : la somme des scans, par entrepôt du secteur (code ou nom) ; un secteur inconnu reste sans entrepôt.
        $this->chez(function () {
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'mesure' => 'SAC', 'prix_unitaire' => 6000]);
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton', 'prix_unitaire' => 5000]);
        });
        foreach ([['CIM-50', 10, 'A1'], ['CIM-50', 5, 'Allée 1'], ['CIM-50', 3, 'B1'], ['CIM-50', 2, 'C1'], ['CIM-50', 1, 'ZZ'], ['FER-10', 4, 'Rayon B']] as [$numero, $quantite, $secteur]) {
            $this->scan($numero, $quantite, $secteur);
        }
        $stock = $this->getJson('/api/stock-entrepots')->assertOk()->json();
        $this->assertSame(['DLA', 'YDE'], array_column($stock['entrepots'], 'code'));
        $ciment = collect($stock['produits'])->firstWhere('numero', 'CIM-50');
        $this->assertEquals([21, 15, 3, 3, 'Ciment 50 kg', 'SAC'], [$ciment['total'], $ciment['entrepots'][$this->douala], $ciment['entrepots'][$this->yaounde],
            $ciment['sans_entrepot'], $ciment['description'], $ciment['mesure']]);
        $this->assertEquals([15, 7, 3, 25], [$stock['totaux']['entrepots'][$this->douala], $stock['totaux']['entrepots'][$this->yaounde], $stock['totaux']['sans_entrepot'], $stock['totaux']['total']]);
        // Un entrepôt : les produits qui y sont ; recherche par description.
        $this->assertSame(['CIM-50'], array_column($this->getJson("/api/stock-entrepots?entrepot_id={$this->douala}")->json('produits'), 'numero'));
        $this->assertSame(['FER-10'], array_column($this->getJson('/api/stock-entrepots?recherche=béton')->json('produits'), 'numero'));
        $csv = $this->get('/api/stock-entrepots?format=csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFproduit;description;unite;DLA;YDE;sans_entrepot;total", $csv);
        $this->assertStringContainsString('CIM-50;"Ciment 50 kg";SAC;15,00;3,00;3,00;21,00', $csv);

        // Historique des scans d'un entrepôt : ses secteurs, par code comme par nom.
        $this->assertEqualsCanonicalizing(['A1', 'Allée 1'], array_column($this->getJson("/api/scan/historique?entrepot_id={$this->douala}&page=1")->assertOk()->json('data'), 'secteur'));

        // Valorisation : les secteurs d'un entrepôt, et le total de chaque entrepôt.
        $this->travelTo('2026-10-17 12:00:00');
        $this->postJson("/api/prises-inventaire/{$prise}/valider")->assertOk();
        $valeurs = $this->getJson('/api/prises-inventaire/valorisation?methode=prix_vente')->assertOk()->json();
        $this->assertEquals([['DLA — Entrepôt de Douala', 90000], ['YDE — Magasin de Yaoundé', 38000], [null, 12000]],
            array_map(fn (array $e) => [$e['entrepot'], $e['valeur']], $valeurs['entrepots']));
        $this->assertSame('DLA — Entrepôt de Douala', collect($valeurs['secteurs'])->firstWhere('secteur', 'A1')['entrepot']);
        $this->assertEquals(['FER-10' => 20000.0, 'CIM-50' => 18000.0], collect($this->getJson("/api/prises-inventaire/valorisation?methode=prix_vente&entrepot_id={$this->yaounde}")
            ->json('produits'))->mapWithKeys(fn ($p) => [$p['numero'] => $p['valeur']])->sortKeysDesc()->all());

        // Entrepôt retiré du référentiel : le secteur qui le porte se réenregistre, un nouveau secteur ne peut plus le choisir.
        $this->chez(fn () => Entrepot::whereKey($this->yaounde)->delete());
        $this->putJson("/api/secteurs/{$b1}", ['nom' => 'Rayon B', 'entrepot_id' => $this->yaounde])->assertOk();
        $this->postJson('/api/secteurs', ['code' => 'D1', 'nom' => 'Dépôt', 'entrepot_id' => $this->yaounde])->assertUnprocessable()->assertJsonValidationErrors(['entrepot_id']);
        // Il garde sa colonne tant qu'il a du stock, désactivé.
        $this->assertEquals([['DLA', true], ['YDE', false]], array_map(fn ($e) => [$e['code'], $e['actif']], $this->getJson('/api/stock-entrepots')->json('entrepots')));
        $this->putJson("/api/secteurs/{$b1}", ['entrepot_id' => null])->assertOk()->assertJsonPath('secteur.entrepot', null);
    }

    public function test_commande_fournisseur_livree_a_un_entrepot(): void
    {
        [$fournisseur, $produit] = $this->chez(fn () => [
            Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Littoral', 'actif' => true, 'devise' => 'XAF'])->id,
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 6000])->id,
        ]);
        $lignes = [['produit_id' => $produit, 'quantite_commandee' => 10, 'prix_unitaire' => 4500]];
        $commande = $this->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-17', 'entrepot_id' => $this->douala, 'lignes' => $lignes])
            ->assertCreated()->assertJsonPath('entrepot.nom', 'Entrepôt de Douala')->json('id');
        $this->getJson("/api/commandes-fournisseur/{$commande}")->assertOk()->assertJsonPath('entrepot.adresse', 'Zone portuaire, rue 12');
        $this->assertSame('DLA', collect($this->getJson('/api/commandes-fournisseur')->json('data'))->firstWhere('id', $commande)['entrepot']['code']);

        // Le bon de commande : « Livrer à » avec l'adresse de l'entrepôt.
        $html = $this->chez(fn () => view('pdf.bon-commande', ['commande' => ComFourEntete::with('fournisseur', 'lignes.produit')->find($commande)] + EntreprisePdf::entete())->render());
        $this->assertStringContainsString('Livrer à', $html);
        $this->assertStringContainsString('Zone portuaire, rue 12', $html);
        $this->assertStringContainsString('Douala', $html);

        // Modification sans le champ : l'entrepôt reste ; vidé : l'adresse de l'entreprise.
        $this->putJson("/api/commandes-fournisseur/{$commande}", ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-17', 'lignes' => $lignes])
            ->assertOk()->assertJsonPath('entrepot.code', 'DLA');
        $this->putJson("/api/commandes-fournisseur/{$commande}", ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-17', 'entrepot_id' => null, 'lignes' => $lignes])
            ->assertOk()->assertJsonPath('entrepot', null);
        $sansEntrepot = $this->chez(fn () => view('pdf.bon-commande', ['commande' => ComFourEntete::with('fournisseur', 'lignes.produit')->find($commande)] + EntreprisePdf::entete())->render());
        $this->assertStringNotContainsString('Livrer à', $sansEntrepot);
    }

    public function test_droits_et_isolation(): void
    {
        // Le stock par entrepôt demande le droit de voir les inventaires.
        $magasinier = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$magasinier->id}", ['groupes' => [], 'exceptions' => ['inventaires.voir' => false]])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($magasinier, ['*']);
        $this->getJson('/api/stock-entrepots')->assertForbidden();

        // L'entrepôt d'une autre entreprise n'existe pas ici.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->postJson('/api/secteurs', ['code' => 'X1', 'nom' => 'X', 'entrepot_id' => $this->douala])->assertUnprocessable()->assertJsonValidationErrors(['entrepot_id']);
        $this->getJson("/api/stock-entrepots?entrepot_id={$this->douala}")->assertUnprocessable();
        $this->scan('CIM-50', 4, 'A1');
        $this->getJson('/api/stock-entrepots')->assertOk()->assertJsonCount(0, 'produits')->assertJsonCount(0, 'entrepots');
    }
}
