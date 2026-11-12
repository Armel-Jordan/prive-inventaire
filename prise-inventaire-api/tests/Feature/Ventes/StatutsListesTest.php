<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Statuts calculés et filtres des listes (plan de parité fripv2, socle S4.7) : statut de livraison des commandes et des
 * factures, documents qui contiennent un produit, âge des factures dues.
 */
class StatutsListesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = $this->chez(fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            'pose' => ProduitTenant::create(['numero' => 'POSE', 'description' => 'Pose', 'prix_unitaire' => 20, 'service' => true, 'stocke' => false])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function commande(string $produit): int
    {
        return $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids[$produit], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
    }

    /** @return array<int, string> id => statut de livraison */
    private function statuts(string $liste, string $filtre = ''): array
    {
        return collect($this->getJson("/api/{$liste}?per_page=50{$filtre}")->assertOk()->json('data'))->pluck('statut_livraison', 'id')->all();
    }

    public function test_statut_de_livraison_des_commandes_et_factures(): void
    {
        $aLivrer = $this->commande('ciment');
        $service = $this->commande('pose');
        $this->assertEquals([$aLivrer => 'non_livre', $service => 'rien_a_livrer'], $this->statuts('commandes-client'));

        // Facturée puis bon de livraison créé : « en attente ».
        $this->postJson("/api/commandes-client/{$aLivrer}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$aLivrer}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$aLivrer}")->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated();
        $this->assertSame('en_attente', $this->statuts('commandes-client')[$aLivrer]);
        $this->assertSame('en_attente', $this->statuts('factures')[$facture]);

        // Filtres.
        $this->assertSame([$aLivrer], array_keys($this->statuts('commandes-client', '&statut_livraison=en_attente')));
        $this->assertSame([$service], array_keys($this->statuts('commandes-client', '&statut_livraison=rien_a_livrer')));
        $this->assertSame([], $this->statuts('commandes-client', '&statut_livraison=livre_complet'));
    }

    public function test_filtre_produit_sur_les_quatre_listes(): void
    {
        $avecCiment = $this->commande('ciment');
        $this->commande('pose');
        $this->assertSame([$avecCiment], array_keys($this->statuts('commandes-client', "&produit_id={$this->ids['ciment']}")));

        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->ids['pose'], 'quantite' => 1, 'prix_unitaire' => 20]]])->json('id');
        $this->assertSame([$devis], array_column($this->getJson("/api/devis?produit_id={$this->ids['pose']}")->json('data'), 'id'));
        $this->assertSame([], $this->getJson("/api/devis?produit_id={$this->ids['ciment']}")->json('data'));
        $this->getJson("/api/factures?produit_id={$this->ids['ciment']}")->assertOk();
        $this->getJson("/api/bons-livraison?produit_id={$this->ids['ciment']}")->assertOk();
    }

    public function test_age_des_factures_dues(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $factures = $this->chez(function () {
            $facture = fn (string $echeance, string $statut = 'emise') => Facture::create(['numero' => 'FAC-'.$echeance.$statut, 'client_id' => $this->ids['client'],
                'date_facture' => '2026-06-01', 'date_echeance' => $echeance, 'statut' => $statut,
                'montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'reste_a_payer' => 100])->id;

            return ['courante' => $facture('2026-11-20'), 'retard45' => $facture('2026-09-28'), 'retard100' => $facture('2026-08-04'), 'payee' => $facture('2026-08-04', 'payee')];
        });

        $ids = fn (string $age) => array_column($this->getJson("/api/factures?age={$age}")->assertOk()->json('data'), 'id');
        $this->assertSame([$factures['courante']], $ids('courant'));
        $this->assertSame([$factures['retard45']], $ids('31_60'));
        $this->assertSame([$factures['retard100']], $ids('plus_90'));
        $this->assertSame([], $ids('1_30'));

        $retards = collect($this->getJson('/api/factures?per_page=50')->json('data'))->pluck('jours_retard', 'id');
        $this->assertSame([0, 45, 100, null], [$retards[$factures['courante']], $retards[$factures['retard45']], $retards[$factures['retard100']], $retards[$factures['payee']]]);
    }
}
