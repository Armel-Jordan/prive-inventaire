<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\HistoriquePrixAchat;
use App\Models\ProduitTenant;
use App\Models\Reception;
use App\Models\ReliquatClient;
use App\Models\RetourFournisseur;
use App\Models\RetourFournisseurLigne;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\ValorisationInventaire;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Réception fournisseur documentée (phase 3.3) : document numéroté regroupant les commandes d'un fournisseur, bon de
 * livraison et connaissement, répartition sur plusieurs secteurs, coût réel (historique, catalogue, valorisation),
 * sur-réception confirmée, clôture d'une ligne avec le reste abandonné visible, historique filtrable et PDF, correction,
 * annulation (commandes, reliquats clients, retours), droits et isolation.
 */
class ReceptionsFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $fournisseur;

    /** @var array<string, int> */
    private array $produits = [];

    /** @var array<string, int> */
    private array $secteurs = [];

    private int $commandes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Joseph Magasinier']);
        $this->chez(function () {
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true]);
            foreach (['CIM' => 'Ciment 50 kg', 'FER' => 'Fer à béton', 'SAB' => 'Sable'] as $numero => $description) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => 100])->id;
            }
            foreach (['A-01', 'B-02'] as $code) {
                $this->secteurs[$code] = Secteur::create(['code' => $code, 'nom' => "Allée {$code}"])->id;
            }
            DB::table('produit_fournisseur')->insert([
                'produit_id' => $this->produits['FER'], 'fournisseur_id' => $this->fournisseur->id, 'prix_achat' => 20,
                'fournisseur_principal' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->agir($this->admin);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    /**
     * Commande envoyée ; lignes [produit, quantité, prix, quantité par unité d'achat].
     *
     * @return array{0: int, 1: list<int>}
     */
    private function commande(array $lignes, ?Fournisseur $fournisseur = null): array
    {
        $numero = 'BC-'.str_pad((string) ++$this->commandes, 5, '0', STR_PAD_LEFT);

        return $this->chez(function () use ($lignes, $fournisseur, $numero) {
            $commande = ComFourEntete::create([
                'numero' => $numero, 'fournisseur_id' => ($fournisseur ?? $this->fournisseur)->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'created_by' => $this->admin->id,
            ]);
            $ids = [];
            foreach ($lignes as [$produit, $quantite, $prix, $parUnite]) {
                $ids[] = ComFourLigne::create([
                    'com_four_entete_id' => $commande->id, 'produit_id' => $this->produits[$produit], 'quantite_commandee' => $quantite,
                    'prix_unitaire' => $prix, 'unite_achat' => $parUnite > 1 ? 'Carton' : null, 'qte_par_unite_achat' => $parUnite,
                ])->id;
            }

            return [$commande->id, $ids];
        });
    }

    private function recevoir(array $lignes, array $autres = [])
    {
        return $this->postJson('/api/receptions/multiple', $autres + ['date_reception' => '2026-10-16', 'receptions' => $lignes]);
    }

    private function ligne(int $id): ComFourLigne
    {
        return ComFourLigne::findOrFail($id);
    }

    private function statut(int $commande): string
    {
        return $this->chez(fn () => ComFourEntete::findOrFail($commande)->statut);
    }

    public function test_une_livraison_devient_un_document_numerote(): void
    {
        [$bc1, [$cim, $fer]] = $this->commande([['CIM', 10, 50, 12], ['FER', 5, 20, 1]]);
        [$bc2, [$sab]] = $this->commande([['SAB', 3, 100, 1]]);
        $autre = $this->chez(fn () => Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Négoce', 'actif' => true]));
        [, [$ailleurs]] = $this->commande([['SAB', 1, 90, 1]], $autre);

        // Une livraison du fournisseur : deux commandes, le ciment rangé dans deux secteurs, le fer facturé 22 au lieu de 20.
        $reception = $this->recevoir([
            ['com_four_ligne_id' => $cim, 'quantite_recue' => 4, 'secteur_id' => $this->secteurs['A-01'], 'cout_unitaire' => 50, 'lot_numero' => 'L-12'],
            ['com_four_ligne_id' => $cim, 'quantite_recue' => 2, 'secteur_id' => $this->secteurs['B-02']],
            ['com_four_ligne_id' => $fer, 'quantite_recue' => 5, 'cout_unitaire' => 22],
            ['com_four_ligne_id' => $sab, 'quantite_recue' => 1],
        ], ['bon_livraison' => 'BL-778', 'connaissement' => 'CN-1', 'notes' => 'Palette abîmée'])
            ->assertCreated()
            ->assertJsonPath('message', 'Réception REC-00001 enregistrée : 4 ligne(s).')
            ->assertJsonPath('reception.numero', 'REC-00001')
            ->assertJsonPath('reception.bon_livraison', 'BL-778')
            ->assertJsonPath('reception.fournisseur.code', 'F1')
            ->assertJsonCount(4, 'receptions')
            ->json('reception.id');

        $this->assertSame([6, 5, 1], [$this->ligne($cim)->quantite_recue, $this->ligne($fer)->quantite_recue, $this->ligne($sab)->quantite_recue]);
        $this->assertSame(['partielle', 'partielle'], [$this->statut($bc1), $this->statut($bc2)]);

        // Coût différent du prix commandé : historique des prix, catalogue du fournisseur, journal.
        $this->chez(function () {
            $this->assertEquals([22], HistoriquePrixAchat::where('produit_id', $this->produits['FER'])->pluck('prix_achat')->map(fn ($p) => (float) $p)->all());
            $this->assertSame(0, HistoriquePrixAchat::where('produit_id', $this->produits['CIM'])->count());
        });
        $this->assertEquals(22, DB::table('produit_fournisseur')->where('produit_id', $this->produits['FER'])->value('prix_achat'));
        $journal = AuditLog::where('model_type', 'Prix fournisseur')->sole();
        $this->assertSame(['F1/FER', 20.0, 22.0, 'Réception REC-00001'], [$journal->model_id, (float) $journal->old_values['prix_achat'], (float) $journal->new_values['prix_achat'], $journal->new_values['source']]);

        // Historique : commandes, valeur (6 × 50 + 5 × 22 + 1 × 100), recherche par bon de livraison, commande, produit.
        $this->getJson('/api/receptions/documents')->assertOk()
            ->assertJsonPath('data.0.numero', 'REC-00001')
            ->assertJsonPath('data.0.commandes', ['BC-00001', 'BC-00002'])
            ->assertJsonPath('data.0.lignes_count', 4)
            ->assertJsonPath('data.0.montant', 510)
            ->assertJsonPath('data.0.auteur.nom', 'Joseph Magasinier');
        foreach (['recherche=BL-778' => 1, 'recherche=BC-00002' => 1, 'recherche=Cimenterie' => 1, 'recherche=inconnu' => 0,
            "produit_id={$this->produits['SAB']}" => 1, "fournisseur_id={$autre->id}" => 0, 'statut=annulee' => 0] as $filtre => $nombre) {
            $this->getJson("/api/receptions/documents?{$filtre}")->assertOk()->assertJsonCount($nombre, 'data');
        }
        $detail = $this->getJson("/api/receptions/documents/{$reception}")->assertOk()
            ->assertJsonPath('connaissement', 'CN-1')->assertJsonCount(4, 'lignes')->json('lignes');
        $this->assertEquals([[50, 200], [50, 100], [22, 110], [100, 100]], array_map(fn (array $l) => [$l['cout_retenu'], $l['montant']], $detail));
        $this->assertSame('L-12', $detail[0]['lot_numero']);
        $this->assertSame('Allée B-02', $detail[1]['secteur']['nom']);
        $pdf = $this->get("/api/receptions/documents/{$reception}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Une réception ne mélange pas les fournisseurs.
        $this->recevoir([['com_four_ligne_id' => $sab, 'quantite_recue' => 1], ['com_four_ligne_id' => $ailleurs, 'quantite_recue' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('receptions');

        // Sur-réception : refusée, puis acceptée sur confirmation ; le reste ne devient pas négatif.
        $this->recevoir([['com_four_ligne_id' => $fer, 'quantite_recue' => 2]])->assertUnprocessable()->assertJsonValidationErrors('receptions');
        $this->recevoir([['com_four_ligne_id' => $fer, 'quantite_recue' => 2]], ['accepter_surplus' => true])->assertCreated()
            ->assertJsonPath('reception.numero', 'REC-00002');
        $this->assertSame([7, 0], [$this->ligne($fer)->quantite_recue, $this->ligne($fer)->quantite_restante]);
    }

    public function test_cloturer_une_ligne_garde_le_reste_abandonne_visible(): void
    {
        [$bc, [$cim, $fer]] = $this->commande([['CIM', 10, 50, 1], ['FER', 4, 20, 1]]);
        $client = $this->chez(fn () => Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $reliquat = $this->chez(fn () => ReliquatClient::create([
            'client_id' => $client, 'produit_id' => $this->produits['CIM'], 'quantite' => 8, 'quantite_commandee' => 8, 'quantite_recue' => 0,
            'prix_unitaire_ht' => 140, 'statut' => ReliquatClient::COMMANDE, 'com_four_ligne_id' => $cim,
        ])->id);

        // 6 sacs reçus, le fournisseur ne livrera pas les 4 autres : la ligne est close, son reliquat client facturable.
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 6]], ['clotures' => [$cim]])->assertCreated()
            ->assertJsonPath('message', 'Réception REC-00001 enregistrée : 1 ligne(s), 1 ligne(s) clôturée(s).')
            ->assertJsonPath('cloturees', [$cim]);
        $ligne = $this->ligne($cim);
        $this->assertSame([6, 6, 4], [$ligne->quantite_commandee, $ligne->quantite_recue, $ligne->quantite_abandonnee]);
        $this->assertEquals(300, $ligne->montant_ligne);
        $this->assertSame([ReliquatClient::RECU, 6], $this->chez(fn () => [ReliquatClient::find($reliquat)->statut, ReliquatClient::find($reliquat)->quantite_recue]));
        $this->assertSame('partielle', $this->statut($bc));

        // Clôturer sans rien recevoir : pas de document, la commande est complète.
        $this->recevoir([], ['clotures' => [$fer]])->assertOk()
            ->assertJsonPath('message', '1 ligne(s) clôturée(s).')->assertJsonPath('reception', null);
        $this->assertSame([0, 4], [$this->ligne($fer)->quantite_commandee, $this->ligne($fer)->quantite_abandonnee]);
        $this->assertSame('complete', $this->statut($bc));
        $this->getJson("/api/commandes-fournisseur/{$bc}")->assertOk()
            ->assertJsonPath('lignes.0.quantite_abandonnee', 4)->assertJsonPath('lignes.0.receptions.0.reception.numero', 'REC-00001');

        // Rien de reçu sur toute la commande : on l'annule, on ne clôture pas toutes ses lignes.
        [$vide, [$seule]] = $this->commande([['SAB', 3, 10, 1]]);
        $this->recevoir([], ['clotures' => [$seule]])->assertUnprocessable()
            ->assertJsonPath('message', "Rien n'a été reçu sur la commande BC-00002 : annulez-la plutôt que de clôturer toutes ses lignes.");
        $this->assertSame([3, 0, 'envoyee'], [$this->ligne($seule)->quantite_commandee, $this->ligne($seule)->quantite_abandonnee, $this->statut($vide)]);
        $this->recevoir([])->assertUnprocessable()->assertJsonValidationErrors('receptions');

        // La clôture d'une commande entière garde aussi le reste abandonné.
        [$partielle, [$sab]] = $this->commande([['SAB', 5, 10, 1]]);
        $this->recevoir([['com_four_ligne_id' => $sab, 'quantite_recue' => 2]])->assertCreated();
        $this->postJson("/api/commandes-fournisseur/{$partielle}/cloturer")->assertOk();
        $this->assertSame([2, 3], [$this->ligne($sab)->quantite_commandee, $this->ligne($sab)->quantite_abandonnee]);
    }

    public function test_annuler_une_reception_retire_ses_quantites(): void
    {
        [$bc, [$cim]] = $this->commande([['CIM', 10, 50, 1]]);
        $client = $this->chez(fn () => Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $reliquat = $this->chez(fn () => ReliquatClient::create([
            'client_id' => $client, 'produit_id' => $this->produits['CIM'], 'quantite' => 5, 'quantite_commandee' => 5, 'quantite_recue' => 0,
            'prix_unitaire_ht' => 140, 'statut' => ReliquatClient::COMMANDE, 'com_four_ligne_id' => $cim,
        ])->id);
        $etatReliquat = fn () => $this->chez(fn () => [ReliquatClient::find($reliquat)->statut, ReliquatClient::find($reliquat)->quantite_recue]);

        $premiere = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 6]])->assertCreated()->json('reception.id');
        $seconde = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 4]])->assertCreated()->json('reception.id');
        $this->assertSame('complete', $this->statut($bc));
        $this->assertSame([ReliquatClient::RECU, 5], $etatReliquat());

        // Seconde réception annulée : la commande attend à nouveau 4 sacs ; le reliquat garde ses 5.
        $this->postJson("/api/receptions/documents/{$seconde}/annuler", ['motif' => 'Saisie en double'])->assertOk()
            ->assertJsonPath('statut', 'annulee')->assertJsonPath('motif_annulation', 'Saisie en double')
            ->assertJsonPath('auteur_annulation.nom', 'Joseph Magasinier');
        $this->assertSame([6, 'partielle'], [$this->ligne($cim)->quantite_recue, $this->statut($bc)]);
        $this->assertSame([ReliquatClient::RECU, 5], $etatReliquat());
        $this->assertCount(1, $this->getJson("/api/receptions?commande_id={$bc}")->assertOk()->json('data'));
        $this->getJson('/api/receptions/documents?statut=annulee')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/receptions/documents/{$seconde}/annuler")->assertUnprocessable()->assertJsonPath('message', 'Cette réception est déjà annulée.');
        $this->putJson("/api/receptions/documents/{$seconde}", ['notes' => 'x'])->assertUnprocessable();

        // Première annulée : rien de reçu, la commande redevient envoyée et le reliquat rend ce qu'il avait reçu.
        $this->postJson("/api/receptions/documents/{$premiere}/annuler")->assertOk();
        $this->assertSame([0, 'envoyee'], [$this->ligne($cim)->quantite_recue, $this->statut($bc)]);
        $this->assertSame([ReliquatClient::COMMANDE, 0], $etatReliquat());

        // Marchandise déjà repartie au fournisseur : l'annulation est refusée.
        $troisieme = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 5]])->assertCreated()->json('reception.id');
        $this->chez(function () use ($bc, $cim) {
            $retour = RetourFournisseur::create([
                'numero' => 'RF-00001', 'fournisseur_id' => $this->fournisseur->id, 'commande_fournisseur_id' => $bc, 'type' => RetourFournisseur::REMBOURSEMENT,
                'statut' => RetourFournisseur::EXPEDIE, 'date_retour' => '2026-10-16', 'montant' => 100, 'cree_par' => $this->admin->id,
            ]);
            RetourFournisseurLigne::create(['retour_fournisseur_id' => $retour->id, 'com_four_ligne_id' => $cim, 'produit_id' => $this->produits['CIM'], 'quantite' => 2, 'prix_unitaire' => 50]);
        });
        $this->postJson("/api/receptions/documents/{$troisieme}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', '2 CIM de la commande BC-00001 sont déjà repartis au fournisseur (retour) : la réception ne peut plus être annulée.');
        $this->assertSame(5, $this->ligne($cim)->quantite_recue);

        // Marchandise déjà facturée au client d'un reliquat : refusé aussi.
        [, [$fer]] = $this->commande([['FER', 3, 20, 1]]);
        $this->chez(fn () => ReliquatClient::create([
            'client_id' => $client, 'produit_id' => $this->produits['FER'], 'quantite' => 3, 'quantite_commandee' => 3, 'quantite_recue' => 0,
            'prix_unitaire_ht' => 30, 'statut' => ReliquatClient::COMMANDE, 'com_four_ligne_id' => $fer,
        ]));
        $facturee = $this->recevoir([['com_four_ligne_id' => $fer, 'quantite_recue' => 3]])->assertCreated()->json('reception.id');
        $this->chez(fn () => ReliquatClient::where('com_four_ligne_id', $fer)->update(['statut' => ReliquatClient::FACTURE]));
        $this->postJson("/api/receptions/documents/{$facturee}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', 'La marchandise FER de cette réception est déjà facturée au client (reliquat Bâtisseurs) : la réception ne peut plus être annulée.');
        $this->assertSame(Reception::ENREGISTREE, $this->chez(fn () => Reception::find($facturee)->statut));
    }

    public function test_corriger_une_reception_et_valoriser_au_cout_reel(): void
    {
        [, [$cim]] = $this->commande([['CIM', 10, 48, 12]]);
        $valeur = fn () => $this->chez(fn () => app(ValorisationInventaire::class)
            ->couts('dernier_achat', ProduitTenant::whereKey($this->produits['CIM'])->get())['CIM']['cout']);
        $this->assertEquals(4, $valeur()); // 48 le carton de 12

        $reception = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 4, 'cout_unitaire' => 54]])->assertCreated()->json('reception');
        $this->assertEquals(4.5, $valeur());

        // Correction : date, références et coût (les quantités ne bougent pas).
        $this->putJson("/api/receptions/documents/{$reception['id']}", [
            'date_reception' => '2026-10-15', 'bon_livraison' => 'BL-9', 'notes' => null,
            'lignes' => [['id' => $reception['lignes'][0]['id'], 'cout_unitaire' => 60]],
        ])->assertOk()->assertJsonPath('bon_livraison', 'BL-9')->assertJsonPath('lignes.0.cout_retenu', 60)
            ->assertJsonPath('lignes.0.date_reception', '2026-10-15T00:00:00.000000Z')->assertJsonPath('montant', 240);
        $this->assertEquals(5, $valeur());

        // Une autre réception de la même ligne, au prix commandé : moyenne pondérée des quantités reçues.
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 2]])->assertCreated();
        $this->assertEquals(round((4 * 60 + 2 * 48) / 6 / 12, 4), $valeur());

        // Coût effacé : retour au prix commandé ; ligne d'une autre réception refusée.
        $this->putJson("/api/receptions/documents/{$reception['id']}", ['lignes' => [['id' => $reception['lignes'][0]['id'], 'cout_unitaire' => null]]])->assertOk();
        $this->assertEquals(4, $valeur());
        $this->putJson("/api/receptions/documents/{$reception['id']}", ['lignes' => [['id' => 999999, 'cout_unitaire' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.id');
    }

    public function test_couts_annexes_repartis_au_cout_de_revient(): void
    {
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);
            ProduitTenant::whereKey($this->produits['CIM'])->update(['poids_kg' => 50]);
            ProduitTenant::whereKey($this->produits['FER'])->update(['poids_kg' => 10]);
        });
        [, [$cim, $fer, $sab]] = $this->commande([['CIM', 10, 50, 1], ['FER', 5, 20, 1], ['SAB', 3, 10, 1]]);
        $lignes = [['com_four_ligne_id' => $cim, 'quantite_recue' => 4], ['com_four_ligne_id' => $fer, 'quantite_recue' => 5]];

        // Transport (300 FCFA) à la valeur : 200 / 100 ; douane (60 EUR à 2 = 120) au poids : 4 × 50 kg / 5 × 10 kg = 96 / 24.
        $reception = $this->recevoir($lignes, ['frais' => [
            ['type' => 'transport', 'libelle' => 'Camion Douala', 'montant' => 300, 'repartition' => 'valeur', 'reference' => 'TR-12'],
            ['type' => 'douane', 'montant' => 60, 'devise' => 'eur', 'taux_change' => 2, 'repartition' => 'poids', 'fournisseur_id' => $this->fournisseur->id],
        ]])->assertCreated()->json('reception');
        $detail = $this->getJson("/api/receptions/documents/{$reception['id']}")->assertOk()
            ->assertJsonPath('montant_frais', 420)->assertJsonPath('valeur_revient', 720)
            ->assertJsonPath('frais.1.devise', 'EUR')->assertJsonPath('frais.1.fournisseur.code', 'F1')->json();
        $this->assertEquals([[74, 124], [24.8, 44.8]], array_map(fn (array $l) => [(float) $l['frais_unitaire'], $l['cout_revient_unitaire']], $detail['lignes']));
        $valeur = fn (string $produit) => $this->chez(fn () => app(ValorisationInventaire::class)
            ->couts('dernier_achat', ProduitTenant::whereKey($this->produits[$produit])->get())[$produit]['cout']);
        $this->assertEquals([124, 44.8], [$valeur('CIM'), $valeur('FER')]);
        $this->getJson('/api/receptions/documents')->assertOk()->assertJsonPath('data.0.montant_frais', 420);
        $this->assertStringStartsWith('%PDF', $this->get("/api/receptions/documents/{$reception['id']}/pdf")->assertOk()->getContent());

        // Correction : coûts remplacés (assurance à la valeur), puis coût d'une ligne corrigé : la répartition suit.
        $this->putJson("/api/receptions/documents/{$reception['id']}", ['frais' => [['type' => 'assurance', 'montant' => 30, 'repartition' => 'valeur']]])
            ->assertOk()->assertJsonCount(1, 'frais')->assertJsonPath('montant_frais', 30);
        $frais = fn () => $this->chez(fn () => Reception::find($reception['id'])->lignes()->orderBy('id')->pluck('frais_unitaire')->map(fn ($f) => $f === null ? null : (float) $f)->all());
        $this->assertSame([5.0, 2.0], $frais());
        $this->putJson("/api/receptions/documents/{$reception['id']}", ['lignes' => [['id' => $reception['lignes'][0]['id'], 'cout_unitaire' => 100]]])->assertOk();
        $this->assertSame([6.0, 1.2], $frais());
        $this->putJson("/api/receptions/documents/{$reception['id']}", ['frais' => []])->assertOk()->assertJsonPath('montant_frais', 0);
        $this->assertSame([null, null], $frais());

        // Refus : devise étrangère sans taux, poids absent des fiches, coûts sans réception.
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 1]], ['frais' => [['type' => 'douane', 'montant' => 10, 'devise' => 'EUR', 'repartition' => 'valeur']]])
            ->assertUnprocessable()->assertJsonValidationErrors('frais.0.taux_change');
        $this->recevoir([['com_four_ligne_id' => $sab, 'quantite_recue' => 1]], ['frais' => [['type' => 'transport', 'montant' => 10, 'repartition' => 'poids']]])
            ->assertUnprocessable()->assertJsonValidationErrors('frais.0.repartition');
        $this->recevoir([], ['clotures' => [$sab], 'frais' => [['type' => 'transport', 'montant' => 10, 'repartition' => 'valeur']]])
            ->assertUnprocessable()->assertJsonValidationErrors('frais');
        $this->assertSame([4, 0], [$this->ligne($cim)->quantite_recue, $this->ligne($sab)->quantite_recue]);
    }

    public function test_droits_numerotation_et_isolation(): void
    {
        [, [$cim]] = $this->commande([['CIM', 10, 50, 1]]);
        $reception = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 2]])->assertCreated()->json('reception.id');

        // Un magasinier sans le droit d'annuler (ni de corriger) les réceptions.
        $magasinier = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$magasinier->id}", ['groupes' => [], 'exceptions' => ['receptions.annuler' => false, 'receptions.modifier' => false]])->assertOk();
        $this->agir($magasinier);
        $this->getJson("/api/receptions/documents/{$reception}")->assertOk();
        $this->postJson("/api/receptions/documents/{$reception}/annuler")->assertForbidden();
        $this->putJson("/api/receptions/documents/{$reception}", ['notes' => 'x'])->assertForbidden();
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 1]])->assertCreated();

        // Une autre entreprise ne voit ni ne touche ces réceptions, ni ces lignes de commande.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson('/api/receptions/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/receptions/documents/{$reception}")->assertNotFound();
        $this->postJson("/api/receptions/documents/{$reception}/annuler")->assertNotFound();
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 1]])->assertUnprocessable()->assertJsonValidationErrors('receptions.0.com_four_ligne_id');
        $this->assertSame(3, $this->ligne($cim)->quantite_recue);

        // Numérotation des réceptions désactivée : la réception est refusée, rien n'est reçu.
        $this->agir($this->admin);
        $this->chez(fn () => Configuration::where('entite', 'reception')->update(['auto_increment' => false]));
        $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 1]])->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des réceptions (Configuration → Numérotation).');
        $this->assertSame(3, $this->ligne($cim)->quantite_recue);
    }
}
