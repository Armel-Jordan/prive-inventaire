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
 * Lots et numéros de série des retours (phase 2.8c) : un retour client remet en stock dans le lot indiqué (sinon le
 * dernier livré au client) et rend les numéros livrés au client (en stock, ou au rebut) ; un retour fournisseur sort le
 * lot indiqué (sinon les premiers à périmer) et les numéros en stock (retournés) ; exemplaires proposés.
 */
class LotsRetoursTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    private int $secteurId;

    private int $commande;

    /** @var array<string, int> */
    private array $produits = [];

    /** @var array<string, int> */
    private array $lignesCommande = [];

    /** @var array<string, int> */
    private array $lots = [];

    /** @var array<string, int> lignes de la facture livrée, par produit */
    private array $lignesFacture = [];

    private int $factureId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->secteurId = $this->chez(fn () => Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id);
        foreach ([['YAOURT', 'Yaourt nature', 'lot'], ['TEL', 'Téléphone', 'serie'], ['SAC', 'Sac', null]] as [$numero, $description, $suivi]) {
            $this->produits[$numero] = $this->postJson('/api/produits', ['numero' => $numero, 'description' => $description, 'secteur_id' => $this->secteurId,
                'prix_unitaire' => 100, 'suivi' => $suivi])->assertCreated()->json('produit.id');
        }
        $this->clientId = $this->chez(fn () => Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Épicerie du Port', 'adresse_facturation' => '1 rue',
            'ville' => 'Douala', 'code_postal' => '0'])->id);

        // Réception (cartons de 6) : L-TARD 12, L-TOT 6 ; trois téléphones.
        [$this->commande, $this->lignesCommande] = $this->chez(function () {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Laiterie du Nord', 'actif' => true]);
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'created_by' => $this->admin->id]);
            $ligne = fn (string $produit, int $quantite, int $parUnite) => ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits[$produit],
                'quantite_commandee' => $quantite, 'prix_unitaire' => 10, 'qte_par_unite_achat' => $parUnite, 'unite_achat' => $parUnite > 1 ? 'CS' : null])->id;

            return [$commande->id, ['YAOURT' => $ligne('YAOURT', 3, 6), 'TEL' => $ligne('TEL', 3, 1)]];
        });
        $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-17', 'receptions' => [
            ['com_four_ligne_id' => $this->lignesCommande['YAOURT'], 'quantite_recue' => 2, 'lot_numero' => 'L-TARD', 'date_peremption' => '2026-12-31', 'secteur_id' => $this->secteurId],
            ['com_four_ligne_id' => $this->lignesCommande['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L-TOT', 'date_peremption' => '2026-11-01', 'secteur_id' => $this->secteurId],
            ['com_four_ligne_id' => $this->lignesCommande['TEL'], 'quantite_recue' => 3, 'numeros_serie' => ['SN-1', 'SN-2', 'SN-3'], 'secteur_id' => $this->secteurId],
        ]])->assertCreated();
        $this->lots = $this->chez(fn () => Lot::query()->pluck('id', 'numero')->all());

        // Livraison au client : 8 yaourts (6 de L-TOT, 2 de L-TARD), SN-1 et SN-2.
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-17', 'lignes' => [
            ['produit_id' => $this->produits['YAOURT'], 'quantite' => 8, 'prix_unitaire_ht' => 100],
            ['produit_id' => $this->produits['TEL'], 'quantite' => 2, 'prix_unitaire_ht' => 100],
        ]])->assertCreated()->json();
        $this->factureId = $facture['id'];
        $this->lignesFacture = collect($facture['lignes'])->mapWithKeys(fn ($l) => [array_search($l['produit_id'], $this->produits, true) => $l['id']])->all();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture['id']}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();
        $lignesBon = collect($bon['lignes'])->mapWithKeys(fn ($l) => [array_search($l['produit_id'], $this->produits, true) => $l['id']])->all();
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [
            ['id' => $lignesBon['YAOURT'], 'quantite_livree' => 8], ['id' => $lignesBon['TEL'], 'quantite_livree' => 2, 'numeros_serie' => ['SN-1', 'SN-2']],
        ]])->assertOk();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function soldes(): array
    {
        return collect($this->getJson('/api/lots')->assertOk()->json('data'))->mapWithKeys(fn ($l) => [$l['numero'] => $l['solde']])->sortKeys()->all();
    }

    private function statuts(): array
    {
        return collect($this->getJson('/api/numeros-serie')->assertOk()->json('data'))->mapWithKeys(fn ($s) => [$s['numero'] => $s['statut']])->sortKeys()->all();
    }

    public function test_retour_client_lots_et_numeros(): void
    {
        $this->assertEquals(['L-TARD' => 10, 'L-TOT' => 0], $this->soldes());

        // Proposés : les lots sortis chez le client (avec ce qu'il a reçu), les numéros qui lui ont été livrés.
        $exemplaires = collect($this->getJson('/api/retours-clients/exemplaires?'.http_build_query(['client_id' => $this->clientId,
            'produits' => [$this->produits['YAOURT'], $this->produits['TEL'], $this->produits['SAC']]]))->assertOk()->json())->keyBy('suivi');
        $this->assertEquals([['L-TARD', 2], ['L-TOT', 6]], array_map(fn ($l) => [$l['numero'], $l['recu_par_client']], $exemplaires['lot']['lots']));
        $this->assertSame(['SN-1', 'SN-2'], $exemplaires['serie']['series']);

        // Refus : un lot d'un autre produit ; un numéro jamais livré à ce client.
        $retour = fn (array $lignes) => $this->postJson('/api/retours-clients', ['facture_id' => $this->factureId, 'date_retour' => '2026-10-17', 'lignes' => $lignes]);
        $yaourt = ['facture_ligne_id' => $this->lignesFacture['YAOURT'], 'quantite' => 3, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteurId];
        $retour([['lot_id' => 999999] + $yaourt])->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.lot_id']);
        $faux = $retour([['facture_ligne_id' => $this->lignesFacture['TEL'], 'quantite' => 1, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteurId,
            'numeros_serie' => ['SN-3']]])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$faux}/traiter")->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.numeros_serie' => "Le numéro de série SN-3 de TEL n'a pas été livré à ce client."]);
        $this->deleteJson("/api/retours-clients/{$faux}")->assertOk();

        // Retour traité : 3 yaourts dans L-TOT, SN-1 remis en stock, SN-2 au rebut.
        $id = $retour([
            ['lot_id' => $this->lots['L-TOT']] + $yaourt,
            ['facture_ligne_id' => $this->lignesFacture['TEL'], 'quantite' => 1, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteurId, 'numeros_serie' => ['SN-1']],
            ['facture_ligne_id' => $this->lignesFacture['TEL'], 'quantite' => 1, 'traitement' => 'rebut', 'numeros_serie' => ['SN-2']],
        ])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$id}/traiter")->assertOk();
        $this->assertEquals(['L-TARD' => 10, 'L-TOT' => 3], $this->soldes());
        $this->assertSame(['SN-1' => 'en_stock', 'SN-2' => 'rebut', 'SN-3' => 'en_stock'], $this->statuts());
        $this->assertNull(collect($this->getJson('/api/numeros-serie')->json('data'))->firstWhere('numero', 'SN-1')['client']);
        $retourLot = collect($this->getJson("/api/lots/{$this->lots['L-TOT']}")->json('mouvements'))->firstWhere('type', 'retour_client');
        $this->assertEquals([3, 'CLI-1 — Épicerie du Port'], [$retourLot['quantite'], $retourLot['client']]);

        // Sans lot indiqué : le dernier lot sorti chez ce client pour ce produit.
        $id = $retour([['facture_ligne_id' => $this->lignesFacture['YAOURT'], 'quantite' => 1, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteurId]])
            ->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$id}/traiter")->assertOk();
        $this->assertEquals(['L-TARD' => 11, 'L-TOT' => 3], $this->soldes());
    }

    public function test_retour_fournisseur_lots_et_numeros(): void
    {
        // Proposés : les lots qui ont du stock (le premier à périmer d'abord), les numéros en stock.
        $exemplaires = collect($this->getJson('/api/retours-fournisseurs/exemplaires?'.http_build_query(['produits' => [$this->produits['YAOURT'], $this->produits['TEL']]]))
            ->assertOk()->json())->keyBy('suivi');
        $this->assertEquals([['L-TARD', 10]], array_map(fn ($l) => [$l['numero'], $l['solde']], $exemplaires['lot']['lots']));
        $this->assertSame(['SN-3'], $exemplaires['serie']['series']);

        // Un carton (6) sort de L-TARD, SN-3 repart ; un numéro déjà livré est refusé.
        $retour = fn (array $lignes) => $this->postJson('/api/retours-fournisseurs', ['commande_fournisseur_id' => $this->commande, 'type' => 'remboursement',
            'date_retour' => '2026-10-17', 'lignes' => $lignes]);
        $tel = ['com_four_ligne_id' => $this->lignesCommande['TEL'], 'quantite' => 1, 'secteur_id' => $this->secteurId];
        $refuse = $retour([['numeros_serie' => ['SN-1']] + $tel])->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$refuse}/expedier")->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.numeros_serie' => "Le numéro de série SN-1 n'est pas en stock."]);
        $this->deleteJson("/api/retours-fournisseurs/{$refuse}")->assertOk();
        $id = $retour([
            ['com_four_ligne_id' => $this->lignesCommande['YAOURT'], 'quantite' => 1, 'secteur_id' => $this->secteurId],
            ['numeros_serie' => ['SN-3']] + $tel,
        ])->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$id}/expedier")->assertOk();
        $this->assertEquals(['L-TARD' => 4, 'L-TOT' => 0], $this->soldes());
        $this->assertSame(['SN-1' => 'livre', 'SN-2' => 'livre', 'SN-3' => 'retourne'], $this->statuts());

        // Lot indiqué sans stock, ou qui n'en a plus assez : refusé, sur le champ du lot.
        $vide = $retour([['com_four_ligne_id' => $this->lignesCommande['YAOURT'], 'quantite' => 1, 'secteur_id' => $this->secteurId, 'lot_id' => $this->lots['L-TOT']]])
            ->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$vide}/expedier")->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.lot_id' => "Ce lot n'a plus de stock ou n'est pas un lot de YAOURT."]);
        $this->deleteJson("/api/retours-fournisseurs/{$vide}")->assertOk();
        $trop = $retour([['com_four_ligne_id' => $this->lignesCommande['YAOURT'], 'quantite' => 1, 'secteur_id' => $this->secteurId, 'lot_id' => $this->lots['L-TARD']]])
            ->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$trop}/expedier")->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.lot_id' => 'Il ne reste que 4 du lot L-TARD.']);
    }
}
