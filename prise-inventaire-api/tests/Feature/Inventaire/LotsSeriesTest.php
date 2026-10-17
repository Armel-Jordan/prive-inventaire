<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\Notification;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use App\Taches\SignalerPeremptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lots et numéros de série (phase 2.8a) : un produit suivi par lot exige son lot (et sa péremption) à la réception, un
 * produit suivi par numéro de série un numéro par unité de stock ; registre des lots (solde, péremption, filtres, CSV,
 * détail, correction) et des numéros de série ; suivi verrouillé ; annulation d'une réception ; alerte de péremption ;
 * droits et isolation.
 */
class LotsSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $produits = [];

    private int $commandes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        // Yaourt suivi par lot avec péremption (cartons de 6), téléphone suivi par numéro de série, sable sans suivi.
        $this->produits['YAOURT'] = $this->postJson('/api/produits', ['numero' => 'YAOURT', 'description' => 'Yaourt nature', 'secteur_id' => $this->secteur(),
            'suivi' => 'lot', 'peremption' => true])->assertCreated()->assertJsonPath('produit.suivi', 'lot')->json('produit.id');
        $this->produits['TEL'] = $this->postJson('/api/produits', ['numero' => 'TEL', 'description' => 'Téléphone', 'secteur_id' => $this->secteur(), 'suivi' => 'serie'])
            ->assertCreated()->json('produit.id');
        $this->produits['SABLE'] = $this->postJson('/api/produits', ['numero' => 'SABLE', 'description' => 'Sable', 'secteur_id' => $this->secteur()])
            ->assertCreated()->assertJsonPath('produit.suivi', null)->json('produit.id');
        $this->postJson('/api/produits', ['numero' => 'X', 'description' => 'X', 'secteur_id' => $this->secteur(), 'suivi' => 'carton'])
            ->assertUnprocessable()->assertJsonValidationErrors(['suivi']);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function secteur(): int
    {
        return $this->chez(fn () => Secteur::firstOrCreate(['code' => 'A1'], ['nom' => 'Allée 1', 'actif' => true])->id);
    }

    /** Commande envoyée ; lignes [produit, quantité, quantité par unité d'achat] ; renvoie les lignes par produit. */
    private function commande(array $lignes): array
    {
        $numero = 'BC-'.++$this->commandes;

        return $this->chez(function () use ($lignes, $numero) {
            $fournisseur = Fournisseur::firstOrCreate(['code' => 'F1'], ['raison_sociale' => 'Laiterie du Nord', 'actif' => true]);
            $commande = ComFourEntete::create(['numero' => $numero, 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'created_by' => $this->admin->id]);
            $ids = [];
            foreach ($lignes as [$produit, $quantite, $parUnite]) {
                $ids[$produit] = ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits[$produit],
                    'quantite_commandee' => $quantite, 'prix_unitaire' => 10, 'unite_achat' => $parUnite > 1 ? 'CS' : null, 'qte_par_unite_achat' => $parUnite])->id;
            }

            return $ids;
        });
    }

    private function recevoir(array $lignes)
    {
        return $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-17', 'receptions' => $lignes]);
    }

    public function test_lots_et_series_a_la_reception(): void
    {
        $l = $this->commande([['YAOURT', 4, 6], ['TEL', 3, 1], ['SABLE', 5, 1]]);

        // Lot exigé, péremption exigée, un numéro de série par unité, pas de doublon.
        $this->recevoir([['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1]])->assertUnprocessable()
            ->assertJsonValidationErrors(['receptions.0.lot_numero' => 'YAOURT se suit par lot : indiquez le numéro de lot.']);
        $this->recevoir([['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L1']])->assertUnprocessable()
            ->assertJsonValidationErrors(['receptions.0.date_peremption']);
        $this->recevoir([['com_four_ligne_id' => $l['TEL'], 'quantite_recue' => 2, 'numeros_serie' => ['SN-1']]])->assertUnprocessable()
            ->assertJsonValidationErrors(['receptions.0.numeros_serie' => 'TEL se suit par numéro de série : saisissez 2 numéro(s), un par unité (1 saisi(s)).']);
        $this->recevoir([['com_four_ligne_id' => $l['TEL'], 'quantite_recue' => 2, 'numeros_serie' => ['SN-1', 'sn-1']]])->assertUnprocessable()
            ->assertJsonValidationErrors(['receptions.0.numeros_serie']);

        // Deux lots du yaourt (cartons de 6), deux téléphones, du sable (son lot reste une mention).
        $reception = $this->recevoir([
            ['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L1', 'date_peremption' => '2027-01-31'],
            ['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 2, 'lot_numero' => 'L2', 'date_peremption' => '2026-10-25'],
            ['com_four_ligne_id' => $l['TEL'], 'quantite_recue' => 2, 'numeros_serie' => [' SN-1 ', 'SN-2', '']],
            ['com_four_ligne_id' => $l['SABLE'], 'quantite_recue' => 5, 'lot_numero' => 'S-9'],
        ])->assertCreated()->json('reception');

        // Registre : la péremption la plus proche d'abord ; L2 à surveiller (dans le préavis de 30 jours).
        $lots = $this->getJson('/api/lots')->assertOk()->assertJsonPath('jours_alerte', 30)->json('data');
        $this->assertEquals([['L2', 12, 12, 'a_surveiller', 8, 'Laiterie du Nord'], ['L1', 6, 6, 'ok', 106, 'Laiterie du Nord']],
            array_map(fn ($x) => [$x['numero'], $x['recu'], $x['solde'], $x['etat'], $x['jours_restants'], $x['fournisseur']], $lots));
        $this->assertSame(['L2'], array_column($this->getJson('/api/lots?etat=a_surveiller')->json('data'), 'numero'));
        $this->assertSame([], $this->getJson('/api/lots?recherche=SABLE')->json('data'));
        $series = $this->getJson('/api/numeros-serie')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['SN-1', 'SN-2'], array_column($series, 'numero'));
        $this->assertSame(['en_stock', $reception['numero']], [$series[0]['statut'], $series[0]['reception']['numero']]);

        // Un lot déjà connu se reçoit avec sa péremption ; un numéro de série déjà enregistré est refusé.
        $this->recevoir([['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L1', 'date_peremption' => '2027-02-28']])
            ->assertUnprocessable()->assertJsonValidationErrors(['receptions.0.date_peremption' => 'Le lot L1 de YAOURT est enregistré avec la péremption du 31/01/2027.']);
        $this->recevoir([['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L1', 'date_peremption' => '2027-01-31']])->assertCreated();
        $this->recevoir([['com_four_ligne_id' => $l['TEL'], 'quantite_recue' => 1, 'numeros_serie' => ['SN-2']]])->assertUnprocessable()
            ->assertJsonValidationErrors(['receptions.0.numeros_serie' => 'Déjà enregistré(s) pour TEL : SN-2.']);
        $l1 = collect($this->getJson('/api/lots')->json('data'))->firstWhere('numero', 'L1');
        $this->assertEquals([12, 12], [$l1['recu'], $l1['solde']]);

        // Détail d'un lot : ses réceptions ; correction de la péremption.
        $detail = $this->getJson("/api/lots/{$l1['id']}")->assertOk()->json();
        $this->assertEquals([['reception', 6, $reception['numero']], ['reception', 6, 'REC-00002']],
            array_map(fn ($m) => [$m['type'], $m['quantite'], $m['reception']['numero']], $detail['mouvements']));
        $this->putJson("/api/lots/{$l1['id']}", ['date_peremption' => '2026-11-10', 'notes' => 'Date corrigée'])->assertOk()
            ->assertJsonPath('date_peremption', '2026-11-10')->assertJsonPath('notes', 'Date corrigée')->assertJsonPath('etat', 'a_surveiller');
        $csv = $this->get('/api/lots?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('YAOURT;"Yaourt nature";L2;2026-10-25;"Laiterie du Nord";12,00;12,00;a_surveiller', $csv);

        // Le suivi ne change plus une fois des lots enregistrés ; celui du sable, si.
        $this->putJson("/api/produits/{$this->produits['YAOURT']}", ['suivi' => null])->assertUnprocessable()->assertJsonValidationErrors(['suivi']);
        $this->putJson("/api/produits/{$this->produits['YAOURT']}", ['peremption' => false])->assertOk();
        $this->putJson("/api/produits/{$this->produits['SABLE']}", ['suivi' => 'lot'])->assertOk()->assertJsonPath('produit.suivi', 'lot');

        // Annulation de la première réception : ses lots contre-passés, ses numéros de série retirés.
        $this->postJson("/api/receptions/documents/{$reception['id']}/annuler")->assertOk();
        $soldes = collect($this->getJson('/api/lots')->json('data'))->mapWithKeys(fn ($x) => [$x['numero'] => $x['solde']]);
        $this->assertEquals(['L2' => 0, 'L1' => 6], $soldes->all());
        $this->assertSame(['L1'], array_column($this->getJson('/api/lots?avec_stock=1')->json('data'), 'numero'));
        $this->assertSame([], $this->getJson('/api/numeros-serie')->json('data'));
    }

    public function test_alerte_de_peremption_droits_et_isolation(): void
    {
        $l = $this->commande([['YAOURT', 4, 6]]);
        $this->recevoir([
            ['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L1', 'date_peremption' => '2026-10-20'],
            ['com_four_ligne_id' => $l['YAOURT'], 'quantite_recue' => 1, 'lot_numero' => 'L2', 'date_peremption' => '2027-06-30'],
        ])->assertCreated();

        // Alerte : le lot qui périme dans le préavis, une seule fois.
        $this->chez(function () {
            $this->assertSame('1 lot(s) signalé(s).', (new SignalerPeremptions)->executer());
            $this->assertSame('Aucun lot à signaler.', (new SignalerPeremptions)->executer());
            $this->assertStringContainsString('Le lot L1 de YAOURT (6 UN restant) périme le 20/10/2026.', Notification::where('type', 'peremption')->value('message'));
        });
        $this->travelTo('2026-10-21 09:00:00');
        $this->assertSame(['L1'], array_column($this->getJson('/api/lots?etat=perimes')->json('data'), 'numero'));

        // Droits : consulter, puis corriger.
        $magasinier = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$magasinier->id}", ['groupes' => [], 'exceptions' => ['lots.modifier' => false]])->assertOk();
        $this->agir($magasinier);
        $lot = $this->getJson('/api/lots')->assertOk()->json('data.0.id');
        $this->putJson("/api/lots/{$lot}", ['notes' => 'x'])->assertForbidden();
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$magasinier->id}", ['groupes' => [], 'exceptions' => ['lots.voir' => false]])->assertOk();
        $this->agir($magasinier);
        $this->getJson('/api/lots')->assertForbidden();
        $this->getJson('/api/numeros-serie')->assertForbidden();

        // Isolation : une autre entreprise ne voit ni ces lots ni leur détail.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson('/api/lots')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/lots/{$lot}")->assertNotFound();
    }
}
