<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Valorisation de l'inventaire officiel (phase 2.6) : quantités des dernières prises validées, au dernier coût
 * d'achat, au coût moyen pondéré ou au prix de vente ; unité d'achat et devise de la commande ; droits ; export.
 */
class ValorisationInventaireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 08:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        [$a, $b] = $this->chez(fn () => [
            Secteur::create(['code' => 'A-01', 'nom' => 'Allée A', 'actif' => true]),
            Secteur::create(['code' => 'B-02', 'nom' => 'Allée B', 'actif' => true]),
        ]);
        $produits = $this->chez(fn () => [
            'CIM-50' => ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'categorie' => 'Liants', 'prix_unitaire' => 6000, 'unite_achat' => 'carton', 'qte_par_unite_achat' => 2]),
            'FER-10' => ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton 10', 'prix_unitaire' => 5000]),
            'SAC-01' => ProduitTenant::create(['numero' => 'SAC-01', 'description' => 'Sable (sac)']),
        ]);

        // Inventaire officiel : une prise validée sur les deux secteurs.
        $prise = $this->postJson('/api/prises-inventaire', ['libelle' => 'Inventaire annuel', 'secteur_ids' => [$a->id, $b->id]])->assertCreated()->json('id');
        foreach ([['CIM-50', 10, 'A-01'], ['FER-10', 4, 'A-01'], ['CIM-50', 5, 'B-02'], ['SAC-01', 7, 'B-02']] as [$numero, $quantite, $secteur]) {
            $this->chez(fn () => ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => $secteur, 'date_saisie' => '2026-10-16 09:00:00']));
        }
        $this->travelTo('2026-10-16 12:00:00');
        $this->postJson("/api/prises-inventaire/{$prise}/valider")->assertOk();

        // Achats : cartons de 2 ; la deuxième commande est en euros (taux figé), le brouillon ne compte pas.
        $this->chez(function () use ($produits) {
            $fournisseur = Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Cimenterie du Littoral']);
            $commande = fn (string $numero, string $date, string $statut, float $taux, array $lignes) => tap(
                ComFourEntete::create(['numero' => $numero, 'fournisseur_id' => $fournisseur->id, 'date_commande' => $date, 'statut' => $statut, 'taux_change' => $taux, 'created_by' => $this->admin->id]),
                fn (ComFourEntete $c) => array_map(fn (array $l) => ComFourLigne::create(['com_four_entete_id' => $c->id, 'produit_id' => $produits[$l[0]]->id,
                    'quantite_commandee' => $l[1], 'quantite_recue' => $l[2], 'qte_par_unite_achat' => $l[3], 'prix_unitaire' => $l[4], 'montant_ligne' => $l[1] * $l[4]]), $lignes),
            );
            $commande('BC-1', '2026-09-01', ComFourEntete::STATUT_COMPLETE, 1, [['CIM-50', 10, 10, 2, 9000], ['FER-10', 4, 0, 1, 5200]]);
            $commande('BC-2', '2026-10-01', ComFourEntete::STATUT_PARTIELLE, 655.957, [['CIM-50', 8, 5, 2, 8]]);
            $commande('BC-3', '2026-10-10', ComFourEntete::STATUT_BROUILLON, 1, [['CIM-50', 1, 0, 2, 1]]);
        });
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** [numéro => [quantité, coût unitaire, valeur]] */
    private function resume(array $valorisation): array
    {
        return collect($valorisation['produits'])->mapWithKeys(fn ($p) => [$p['numero'] => [$p['quantite'], $p['cout_unitaire'], $p['valeur']]])->all();
    }

    public function test_trois_methodes_de_valorisation(): void
    {
        $moyen = $this->getJson('/api/prises-inventaire/valorisation')->assertOk()->assertJsonPath('methode', 'cout_moyen')->json();
        // CIM-50 : 20 unités reçues à 4 500, puis 10 à 8 € × 655,957 / 2 = 2 623,828.
        $this->assertEquals(['CIM-50' => [15, 3874.6093, 58119.14], 'FER-10' => [4, 5200, 20800], 'SAC-01' => [7, null, null]], $this->resume($moyen));
        $this->assertEquals([78919.14, 3, 1], [$moyen['total'], $moyen['nb_produits'], $moyen['nb_sans_cout']]);
        $this->assertEquals(['A-01', 'B-02'], $moyen['produits'][0]['secteurs']);
        $secteurs = collect($moyen['secteurs'])->keyBy('secteur');
        $this->assertEquals([59546.09, 2, 0], [$secteurs['A-01']['valeur'], $secteurs['A-01']['nb_produits'], $secteurs['A-01']['nb_sans_cout']]);
        $this->assertEquals([19373.05, 1], [$secteurs['B-02']['valeur'], $secteurs['B-02']['nb_sans_cout']]);
        $this->assertSame('INV-2026-0001', $secteurs['A-01']['prise']['numero']);

        $dernier = $this->getJson('/api/prises-inventaire/valorisation?methode=dernier_achat')->assertOk()->json();
        $this->assertEquals(['CIM-50' => [15, 2623.828, 39357.42], 'FER-10' => [4, 5200, 20800], 'SAC-01' => [7, null, null]], $this->resume($dernier));
        $this->assertSame('BC-2 01/10/2026', $dernier['produits'][0]['reference']);

        $vente = $this->getJson('/api/prises-inventaire/valorisation?methode=prix_vente')->assertOk()->json();
        $this->assertEquals(['CIM-50' => [15, 6000, 90000], 'FER-10' => [4, 5000, 20000], 'SAC-01' => [7, null, null]], $this->resume($vente));
        $this->assertEquals(110000, $vente['total']);

        // Un seul secteur.
        $b = $this->chez(fn () => Secteur::where('code', 'B-02')->value('id'));
        $this->assertEquals(['CIM-50', 'SAC-01'], array_column($this->getJson("/api/prises-inventaire/valorisation?secteur_id={$b}")->json('produits'), 'numero'));
    }

    public function test_export_droits_et_isolation(): void
    {
        $csv = $this->get('/api/prises-inventaire/valorisation?methode=dernier_achat&format=csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFproduit;description;categorie;secteurs;quantite;cout_unitaire;valeur;reference", $csv);
        $this->assertStringContainsString('CIM-50;"Ciment 50 kg";Liants;"A-01, B-02";15,00;2623,83;39357,42;"BC-2 01/10/2026"', $csv);
        $this->assertStringContainsString(';Total;;;;;60157,42;', $csv);

        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']), ['*']);
        $this->getJson('/api/prises-inventaire/valorisation')->assertForbidden();

        $autre = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson('/api/prises-inventaire/valorisation')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'produits');
    }
}
