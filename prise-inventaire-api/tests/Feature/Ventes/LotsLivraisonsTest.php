<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\Lot;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lots et numéros de série à la livraison (phase 2.8b) : un bon de livraison sort les lots indiqués, sinon les premiers à
 * périmer, et les numéros de série indiqués ; une vente au comptoir sort les premiers lots ; registres et rappel d'un
 * lot (qui l'a reçu) ; une réception dont le lot est entamé ne s'annule plus.
 */
class LotsLivraisonsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    /** @var array<string, int> */
    private array $produits = [];

    /** @var array<string, int> */
    private array $lots = [];

    private int $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $secteur = $this->chez(fn () => Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id);
        foreach ([['YAOURT', 'Yaourt nature', 'lot', 500], ['TEL', 'Téléphone', 'serie', 100000]] as [$numero, $description, $suivi, $prix]) {
            $this->produits[$numero] = $this->postJson('/api/produits', ['numero' => $numero, 'description' => $description, 'secteur_id' => $secteur,
                'prix_unitaire' => $prix, 'suivi' => $suivi])->assertCreated()->json('produit.id');
        }
        $this->clientId = $this->chez(fn () => Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Épicerie du Port', 'adresse_facturation' => '1 rue',
            'ville' => 'Douala', 'code_postal' => '0', 'telephone' => '+237 600 00 00 00'])->id);

        // Réception : yaourts en cartons de 6 (L-TARD : 12, L-TOT : 6, péremption plus proche), trois téléphones.
        $lignes = $this->chez(function () {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Laiterie du Nord', 'actif' => true]);
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'created_by' => $this->admin->id]);
            $ligne = fn (string $produit, int $quantite, int $parUnite) => ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits[$produit],
                'quantite_commandee' => $quantite, 'prix_unitaire' => 10, 'qte_par_unite_achat' => $parUnite, 'unite_achat' => $parUnite > 1 ? 'CS' : null])->id;

            return ['YAOURT' => $ligne('YAOURT', 3, 6), 'TEL' => $ligne('TEL', 3, 1)];
        });
        $this->reception = $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-17', 'receptions' => [
            ['com_four_ligne_id' => $lignes['YAOURT'], 'quantite_recue' => 2, 'lot_numero' => 'L-TARD', 'date_peremption' => '2026-12-31'],
            ['com_four_ligne_id' => $lignes['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L-TOT', 'date_peremption' => '2026-11-01'],
            ['com_four_ligne_id' => $lignes['TEL'], 'quantite_recue' => 3, 'numeros_serie' => ['SN-1', 'SN-2', 'SN-3']],
        ]])->assertCreated()->json('reception.id');
        $this->lots = $this->chez(fn () => Lot::query()->pluck('id', 'numero')->all());
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Bon de livraison prêt d'une facture émise ; lignes [produit => quantité] ; renvoie le bon et ses lignes par produit. */
    private function bonPret(array $quantites): array
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-17',
            'lignes' => array_map(fn ($produit, $quantite) => ['produit_id' => $this->produits[$produit], 'quantite' => $quantite, 'prix_unitaire_ht' => 100], array_keys($quantites), $quantites)])
            ->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();
        $lignes = collect($bon['lignes'])->mapWithKeys(fn ($l) => [array_search($l['produit_id'], $this->produits, true) => $l['id']])->all();

        return [$bon, $lignes];
    }

    /** Solde de chaque lot. */
    private function soldes(): array
    {
        return collect($this->getJson('/api/lots')->assertOk()->json('data'))->mapWithKeys(fn ($l) => [$l['numero'] => $l['solde']])->sortKeys()->all();
    }

    public function test_livraison_premiers_lots_et_numeros_de_serie(): void
    {
        [$bon, $l] = $this->bonPret(['YAOURT' => 8, 'TEL' => 2]);

        // À sortir : les lots dans l'ordre de sortie (premier à périmer d'abord), les numéros de série en stock.
        $exemplaires = collect($this->getJson("/api/bons-livraison/{$bon['id']}/exemplaires")->assertOk()->json())->keyBy('suivi');
        $this->assertSame([['L-TOT', 6], ['L-TARD', 12]], array_map(fn ($x) => [$x['numero'], $x['solde']], $exemplaires['lot']['lots']));
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], $exemplaires['serie']['series']);

        // Refus : numéro inconnu, trop de numéros, un lot au-delà de son stock.
        $livrer = fn (array $yaourt, array $tel) => $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [
            ['id' => $l['YAOURT'], 'quantite_livree' => 8] + $yaourt, ['id' => $l['TEL'], 'quantite_livree' => 2] + $tel]]);
        $livrer([], ['numeros_serie' => ['SN-1', 'SN-9']])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.1.numeros_serie' => 'Numéro de série inconnu pour TEL : SN-9.']);
        $livrer([], ['numeros_serie' => ['SN-1', 'SN-2', 'SN-3']])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.1.numeros_serie' => 'TEL : 3 numéro(s) de série pour 2 unité(s) livrée(s).']);
        $livrer(['lots' => [['lot_id' => $this->lots['L-TOT'], 'quantite' => 7]]], [])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.lots.0.quantite']);

        // Livraison : les premiers à périmer (6 de L-TOT, 2 de L-TARD), deux téléphones choisis.
        $livrer([], ['numeros_serie' => ['SN-1', 'SN-3']])->assertOk()->assertJsonPath('statut', 'livre_complet');
        $this->assertEquals(['L-TARD' => 10, 'L-TOT' => 0], $this->soldes());
        $this->assertSame(['L-TARD'], array_column($this->getJson('/api/lots?avec_stock=1')->json('data'), 'numero'));
        $series = collect($this->getJson('/api/numeros-serie')->json('data'))->keyBy('numero');
        $this->assertEquals([['livre', 'CLI-1 — Épicerie du Port', $bon['numero']], ['en_stock', null, null], ['livre', 'CLI-1 — Épicerie du Port', $bon['numero']]],
            array_map(fn ($n) => [$series[$n]['statut'], $series[$n]['client'], $series[$n]['bon_livraison']], ['SN-1', 'SN-2', 'SN-3']));

        // Rappel de L-TOT : qui l'a reçu ; CSV ; détail du lot.
        $rappel = $this->getJson("/api/lots/{$this->lots['L-TOT']}/rappel")->assertOk()->json();
        $this->assertEquals([[$bon['numero'], 'livraison', 6, 'CLI-1', '+237 600 00 00 00']],
            array_map(fn ($s) => [$s['document'], $s['type'], $s['quantite'], $s['client']['code'], $s['client']['telephone']], $rappel['sorties']));
        $this->assertSame(0, (int) $rappel['lot']['solde']);
        $csv = $this->get("/api/lots/{$this->lots['L-TOT']}/rappel?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString("2026-10-17;{$bon['numero']};CLI-1;\"Épicerie du Port\";\"+237 600 00 00 00\";;6,00", $csv);
        $sortie = collect($this->getJson("/api/lots/{$this->lots['L-TARD']}")->json('mouvements'))->firstWhere('type', 'livraison');
        $this->assertEquals([-2, $bon['numero'], 'CLI-1 — Épicerie du Port'], [$sortie['quantite'], $sortie['document'], $sortie['client']]);

        // La réception dont un lot a servi ne s'annule plus.
        $this->postJson("/api/receptions/documents/{$this->reception}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro(s) de série déjà sorti(s) : SN-1, SN-3. La réception ne peut plus être annulée.');
    }

    public function test_lots_choisis_et_vente_au_comptoir(): void
    {
        // Lot choisi à la livraison : L-TARD plutôt que le premier à périmer.
        [$bon, $l] = $this->bonPret(['YAOURT' => 4]);
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [
            ['id' => $l['YAOURT'], 'quantite_livree' => 4, 'lots' => [['lot_id' => $this->lots['L-TARD'], 'quantite' => 4]]]]])->assertOk();
        $this->assertEquals(['L-TARD' => 8, 'L-TOT' => 6], $this->soldes());

        // Vente au comptoir : les premiers lots à sortir (6 de L-TOT, 1 de L-TARD) ; au-delà des lots, la vente passe sans lot.
        $vente = $this->postJson('/api/point-de-vente/ventes', ['client_id' => $this->clientId, 'date' => '2026-10-17', 'paiements' => [],
            'lignes' => [['produit_id' => $this->produits['YAOURT'], 'quantite' => 7]]])->assertCreated()->json('facture.numero');
        $this->assertEquals(['L-TARD' => 7, 'L-TOT' => 0], $this->soldes());
        $this->postJson('/api/point-de-vente/ventes', ['client_id' => $this->clientId, 'date' => '2026-10-17', 'paiements' => [],
            'lignes' => [['produit_id' => $this->produits['YAOURT'], 'quantite' => 20]]])->assertCreated();
        $this->assertEquals(['L-TARD' => 0, 'L-TOT' => 0], $this->soldes());

        // Rappel de L-TARD : la livraison et les deux ventes au comptoir.
        $sorties = $this->getJson("/api/lots/{$this->lots['L-TARD']}/rappel")->assertOk()->json('sorties');
        $this->assertEquals([[$bon['numero'], 'livraison', 4], [$vente, 'vente_comptoir', 1], [null, 'vente_comptoir', 7]],
            array_map(fn ($s) => [$s['type'] === 'vente_comptoir' && $s['quantite'] == 7 ? null : $s['document'], $s['type'], $s['quantite']], $sorties));

        // Les lots ont servi : la réception ne s'annule plus.
        $this->postJson("/api/receptions/documents/{$this->reception}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', 'Le lot L-TARD a déjà été entamé (0 sur 12 reçus) : la réception ne peut plus être annulée.');
    }
}
