<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Facture;
use App\Models\FacturePaiement;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Point de vente (phase 1.10b) : recherche des produits, aperçu au prix du client avec les taxes, vente au comptoir
 * (facture créée, émise et encaissée sur la caisse du poste), paiement en plusieurs modes avec monnaie rendue, règles
 * du client de passage et des espèces, vente en compte, reçu ; droits et isolation.
 */
class PointDeVenteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    /** @var array<string, int> */
    private array $produits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Caissière']);
        [$this->clientId, $this->produits] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA', 'tva_taux' => 19.25]);
            $produit = fn (string $numero, string $description, ?float $prix) => ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => $prix])->id;

            return [
                Client::create(['code' => 'CLI-A', 'raison_sociale' => 'Bâtiments Ngando', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'taux_remise_global' => 10])->id,
                ['CIM' => $produit('CIM', 'Ciment 50 kg', 1000), 'FER' => $produit('FER', 'Fer à béton', 2500), 'VIS' => $produit('VIS', 'Vis au détail', null)],
            ];
        });
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function ligne(string $produit, int $quantite, ?float $prix = null): array
    {
        return array_filter(['produit_id' => $this->produits[$produit], 'quantite' => $quantite, 'prix_unitaire_ht' => $prix], fn ($v) => $v !== null);
    }

    private function vendre(array $donnees)
    {
        return $this->postJson('/api/point-de-vente/ventes', $donnees + ['date' => '2026-10-17', 'paiements' => []]);
    }

    public function test_vente_au_comptoir_de_l_apercu_au_recu(): void
    {
        $caisse = $this->postJson('/api/referentiels/caisses', ['code' => 'C1', 'nom' => 'Comptoir', 'actif' => true])->assertCreated()->json('id');
        $session = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 0])->assertCreated()->json('id');

        // Recherche : le numéro exact d'abord, la désignation aussi.
        $this->assertSame(['CIM'], array_column($this->getJson('/api/point-de-vente/produits?recherche=CIM')->assertOk()->json(), 'numero'));
        $this->assertSame(['FER'], array_column($this->getJson('/api/point-de-vente/produits?recherche=béton')->assertOk()->json(), 'numero'));
        $this->assertSame([], $this->getJson('/api/point-de-vente/produits')->assertOk()->json());

        // Aperçu au client de passage : prix de base, taxes au taux de l'entreprise ; un produit sans prix est signalé.
        $apercu = $this->postJson('/api/point-de-vente/calculer', ['lignes' => [$this->ligne('CIM', 2), $this->ligne('FER', 1), $this->ligne('VIS', 3)]])->assertOk()->json();
        $this->assertTrue($apercu['client']['de_passage']);
        $this->assertEquals([[1000, false, 2385], [2500, false, 2981.25], [null, true, null]],
            array_map(fn ($l) => [$l['prix_unitaire_ht'], $l['sans_prix'], $l['montant_ttc']], $apercu['lignes']));
        $this->assertEquals([4500, 866.25, 5366.25], [$apercu['montant_ht'], $apercu['montant_tva'], $apercu['montant_ttc']]);
        // Au client en compte : sa remise de 10 %.
        $this->assertEquals([900, 2250], array_column($this->postJson('/api/point-de-vente/calculer', [
            'client_id' => $this->clientId, 'lignes' => [$this->ligne('CIM', 1), $this->ligne('FER', 1)],
        ])->assertOk()->json('lignes'), 'prix_unitaire_ht'));

        // Refus : produit sans prix, client de passage qui ne paie pas tout, carte qui dépasse.
        $lignes = [$this->ligne('CIM', 2), $this->ligne('FER', 1), $this->ligne('VIS', 1)];
        $this->vendre(['lignes' => $lignes, 'caisse_id' => $caisse])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Aucun prix pour VIS : saisissez-le.']);
        $lignes[2]['prix_unitaire_ht'] = 100;
        $this->vendre(['lignes' => $lignes, 'caisse_id' => $caisse, 'paiements' => [['mode' => 'carte', 'montant' => 1000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['paiements' => 'Le client de passage paie toute la vente : il manque 4 485,50 FCFA.']);
        $this->vendre(['lignes' => $lignes, 'caisse_id' => $caisse, 'paiements' => [['mode' => 'carte', 'montant' => 6000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['paiements' => 'Seules les espèces peuvent dépasser le montant dû (la monnaie est rendue) : ramenez les autres modes à 5 485,50 FCFA au plus.']);
        $this->assertSame(0, Facture::count());

        // Vente : carte 3 000 et espèces 3 000 pour 5 485,50 ; monnaie rendue 514,50, seul le dû est encaissé.
        $vente = $this->vendre(['lignes' => $lignes, 'caisse_id' => $caisse, 'passage_nom' => 'Paul Ekwalla', 'paiements' => [
            ['mode' => 'carte', 'montant' => 3000, 'reference' => 'TPE-1'], ['mode' => 'especes', 'montant' => 3000],
        ]])->assertCreated()->json();
        $this->assertEquals(514.5, $vente['monnaie']);
        $facture = $vente['facture'];
        $this->assertSame(['payee', 'Paul Ekwalla', 'Vente '.$facture['numero'].' enregistrée.'], [$facture['statut'], $facture['passage_nom'], $vente['message']]);
        $this->assertEquals([5485.5, 0], [$facture['montant_ttc'], $facture['reste_a_payer']]);
        $this->assertEquals([['carte', 3000, null, 'TPE-1', $session], ['especes', 2485.5, 3000, null, $session]],
            FacturePaiement::orderBy('id')->get()->map(fn ($p) => [$p->mode_paiement, (float) $p->montant, $p->montant_recu === null ? null : (float) $p->montant_recu, $p->reference, $p->caisse_session_id])->all());
        // Dans la caisse : ce qui est dû, pas l'argent remis.
        $lignesCaisse = collect($this->getJson("/api/caisses/sessions/{$session}")->assertOk()->json('lignes'))->keyBy('mode');
        $this->assertEquals([2485.5, 3000], [$lignesCaisse['especes']['encaissements'], $lignesCaisse['carte']['encaissements']]);
        // Reçu (ticket de 80 mm).
        $this->assertStringStartsWith('%PDF', $this->get("/api/point-de-vente/recus/{$facture['id']}?fuseau=Africa/Douala")->assertOk()->getContent());

        // Client en compte : vente sans paiement (portée au compte), paiement partiel par carte ; pas d'espèces partielles.
        $enCompte = $this->vendre(['client_id' => $this->clientId, 'lignes' => [$this->ligne('CIM', 1)]])->assertCreated()->json();
        $this->assertSame(['emise', 'Vente '.$enCompte['facture']['numero'].' enregistrée. Le reste est porté au compte du client.'], [$enCompte['facture']['statut'], $enCompte['message']]);
        $this->assertEquals(1073.25, $enCompte['facture']['reste_a_payer']);
        $partiel = $this->vendre(['client_id' => $this->clientId, 'lignes' => [$this->ligne('FER', 1)], 'paiements' => [['mode' => 'cheque', 'montant' => 1000]]])->assertCreated()->json('facture');
        $this->assertEquals(['partiellement_payee', 1683.13], [$partiel['statut'], round((float) $partiel['reste_a_payer'], 2)]);
        $this->vendre(['client_id' => $this->clientId, 'lignes' => [$this->ligne('FER', 1)], 'paiements' => [['mode' => 'especes', 'montant' => 1000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['paiements' => 'Un paiement en espèces règle toute la vente (la monnaie est rendue) : il manque 1 683,13 FCFA.']);
        $this->get("/api/point-de-vente/recus/{$enCompte['facture']['id']}")->assertOk();

        // Caisse fermée : la vente est refusée et rien n'est créé (pas même un numéro).
        $this->postJson("/api/caisses/sessions/{$session}/fermer", ['comptes' => []])->assertOk();
        $avant = Facture::count();
        $this->vendre(['lignes' => [$this->ligne('CIM', 1)], 'caisse_id' => $caisse, 'paiements' => [['mode' => 'especes', 'montant' => 2000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['caisse_id' => "La caisse C1 n'est pas ouverte : ouvrez-la avant d'encaisser."]);
        $this->assertSame($avant, Facture::count());
        $suivante = $this->vendre(['lignes' => [$this->ligne('CIM', 1)], 'paiements' => [['mode' => 'especes', 'montant' => 2000]]])->assertCreated()->json('facture');
        $this->assertNull(FacturePaiement::where('facture_id', $suivante['id'])->value('caisse_session_id'));
        // Numéros sans trou : les ventes refusées n'en ont consommé aucun.
        $numeros = Facture::orderBy('id')->pluck('numero')->map(fn ($n) => (int) preg_replace('/\D/', '', $n))->all();
        $this->assertSame(range($numeros[0], $numeros[0] + count($numeros) - 1), $numeros);
    }

    public function test_droits_et_isolation(): void
    {
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $regler = function (array $exceptions) use ($vendeur) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($vendeur->fresh());
        };
        $vente = ['lignes' => [$this->ligne('CIM', 1)], 'paiements' => [['mode' => 'especes', 'montant' => 2000]]];

        $regler(['point_de_vente.vendre' => false]);
        $this->vendre($vente)->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Vendre au comptoir » (Point de vente).');
        // L'aperçu reste ouvert : un calcul, rien n'est écrit.
        $this->postJson('/api/point-de-vente/calculer', ['lignes' => [$this->ligne('CIM', 1)]])->assertOk();
        $regler(['factures.encaisser' => false]);
        $this->vendre($vente)->assertForbidden()->assertJsonPath('action', 'encaisser');
        $this->vendre(['client_id' => $this->clientId, 'lignes' => [$this->ligne('CIM', 1)]])->assertCreated();
        $regler(['factures.emettre' => false]);
        $this->vendre($vente)->assertForbidden()->assertJsonPath('action', 'emettre');
        $regler([]);
        $facture = $this->vendre($vente)->assertCreated()->json('facture.id');

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['point_de_vente.voir', 'point_de_vente.vendre'], array_column($modules['point_de_vente']['permissions'], 'code'));
        // Une facture brouillon n'a pas de reçu.
        $brouillon = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-17',
            'lignes' => [['produit_id' => $this->produits['CIM'], 'quantite' => 1, 'prix_unitaire_ht' => 1000]]])->assertCreated()->json('id');
        $this->getJson("/api/point-de-vente/recus/{$brouillon}")->assertUnprocessable()->assertJsonPath('message', "Une facture brouillon n'a pas de reçu.");

        // Une autre entreprise ne voit ni les produits ni les reçus, et ne vend pas les produits d'une autre.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame([], $this->getJson('/api/point-de-vente/produits?recherche=CIM')->assertOk()->json());
        $this->get("/api/point-de-vente/recus/{$facture}")->assertNotFound();
        $this->vendre($vente)->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');
    }
}
