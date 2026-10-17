<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ReliquatClient;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unités d'achat (phase 2.2, suite) : conditionnement de chaque fournisseur (sinon celui du produit) repris par ses
 * commandes ; ce qui se compte en stock converti avec le facteur de la ligne : en commande des disponibilités,
 * reliquats commandés en unités d'achat entières et servis par les réceptions, suggestions, retours au fournisseur.
 */
class UnitesAchatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $biere;

    private int $brasserie;

    private int $grossiste;

    private int $clientId;

    private int $secteurId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->chez(function () {
            $this->secteurId = Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id;
            $this->brasserie = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Brasserie', 'actif' => true, 'devise' => 'XAF'])->id;
            $this->grossiste = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Grossiste', 'actif' => true, 'devise' => 'XAF'])->id;
            // Le produit s'achète d'ordinaire par pack de 6 ; la brasserie livre des caisses de 24.
            $this->biere = ProduitTenant::create(['numero' => 'BIERE', 'description' => 'Bière 33 cl', 'mesure' => 'UN', 'prix_unitaire' => 100,
                'unite_achat' => 'PACK', 'qte_par_unite_achat' => 6, 'seuil_alerte' => 50, 'actif' => true, 'secteur_id' => $this->secteurId,
                'fournisseur_principal_id' => $this->brasserie])->id;
            foreach ([[$this->brasserie, 1500, true], [$this->grossiste, 400, false]] as [$fournisseur, $prix, $principal]) {
                DB::table('produit_fournisseur')->insert(['produit_id' => $this->biere, 'fournisseur_id' => $fournisseur, 'prix_achat' => $prix,
                    'fournisseur_principal' => $principal, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->clientId = Client::create(['code' => 'CLI', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
        });
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Magasinier']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        // La brasserie : caisses de 24.
        $this->putJson("/api/fournisseurs/{$this->brasserie}/produits/{$this->biere}", ['unite_achat' => 'cs', 'qte_par_unite_achat' => 24, 'prix_achat' => 1500])->assertOk();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function commande(int $fournisseur, int $quantite, float $prix): array
    {
        $commande = $this->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-17',
            'lignes' => [['produit_id' => $this->biere, 'quantite_commandee' => $quantite, 'prix_unitaire' => $prix]]])->assertCreated()->json();

        return [$commande['id'], $commande['lignes'][0]];
    }

    private function recevoir(int $ligne, int $quantite): int
    {
        return $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-17', 'receptions' => [
            ['com_four_ligne_id' => $ligne, 'quantite_recue' => $quantite, 'secteur_id' => $this->secteurId],
        ]])->assertCreated()->json('reception.id');
    }

    private function disponibilite(): array
    {
        return collect($this->getJson('/api/disponibilites?tous=1')->assertOk()->json('data'))->firstWhere('produit_id', $this->biere);
    }

    public function test_conditionnement_du_fournisseur_sur_ses_commandes(): void
    {
        // Le catalogue dit le conditionnement de chaque fournisseur, et celui du produit.
        $catalogue = collect($this->getJson("/api/fournisseurs/{$this->brasserie}/produits")->assertOk()->json())->firstWhere('produit_id', $this->biere);
        $this->assertEquals(['CS', 24, 'UN', 'PACK', 6], [$catalogue['unite_achat'], $catalogue['qte_par_unite_achat'], $catalogue['mesure'],
            $catalogue['unite_achat_produit'], $catalogue['qte_par_unite_achat_produit']]);

        // La brasserie : 2 caisses de 24 ; le grossiste, sans conditionnement propre : des packs de 6.
        [$chezBrasserie, $ligne] = $this->commande($this->brasserie, 2, 1500);
        $this->assertSame(['CS', 24], [$ligne['unite_achat'], $ligne['qte_par_unite_achat']]);
        [$chezGrossiste, $ligneGrossiste] = $this->commande($this->grossiste, 3, 400);
        $this->assertSame(['PACK', 6], [$ligneGrossiste['unite_achat'], $ligneGrossiste['qte_par_unite_achat']]);
        // Commande passée chez l'autre fournisseur : son conditionnement.
        $modifiee = $this->putJson("/api/commandes-fournisseur/{$chezGrossiste}", ['fournisseur_id' => $this->brasserie, 'date_commande' => '2026-10-17',
            'lignes' => [['id' => $ligneGrossiste['id'], 'produit_id' => $this->biere, 'quantite_commandee' => 3, 'prix_unitaire' => 1500]]])->assertOk()->json('lignes.0');
        $this->assertSame(['CS', 24], [$modifiee['unite_achat'], $modifiee['qte_par_unite_achat']]);

        // En commande, en unités de stock : 2 caisses attendues = 48 ; une caisse reçue, 24.
        $this->postJson("/api/commandes-fournisseur/{$chezBrasserie}/valider")->assertOk();
        $this->assertSame(48, $this->disponibilite()['en_commande']);
        $this->recevoir($ligne['id'], 1);
        $this->assertSame(24, $this->disponibilite()['en_commande']);
    }

    public function test_reliquats_et_suggestions_commandes_en_unites_d_achat(): void
    {
        // Un reliquat de 30 unités se commande en 2 caisses de 24.
        $reliquat = $this->postJson('/api/reliquats-clients', ['client_id' => $this->clientId, 'produit_id' => $this->biere, 'quantite' => 30, 'prix_unitaire_ht' => 150])
            ->assertCreated()->json('id');
        $commande = $this->postJson('/api/reliquats-clients/commander', ['ids' => [$reliquat]])->assertCreated()->json('0');
        $ligne = $commande['lignes'][0];
        $this->assertSame([2, 'CS', 24, $this->brasserie], [$ligne['quantite_commandee'], $ligne['unite_achat'], $ligne['qte_par_unite_achat'], $commande['fournisseur_id']]);
        $this->postJson("/api/commandes-fournisseur/{$commande['id']}/valider")->assertOk();

        // Réceptions en caisses : 24 unités servent le reliquat en partie, la seconde caisse le complète.
        $etat = fn () => $this->chez(fn () => [ReliquatClient::find($reliquat)->statut, ReliquatClient::find($reliquat)->quantite_recue]);
        $this->recevoir($ligne['id'], 1);
        $this->assertSame([ReliquatClient::RECU_PARTIEL, 24], $etat());
        $seconde = $this->recevoir($ligne['id'], 1);
        $this->assertSame([ReliquatClient::RECU, 30], $etat());
        // La seconde réception annulée : le reliquat revient à ce que couvre la première caisse.
        $this->postJson("/api/receptions/documents/{$seconde}/annuler", ['motif' => 'Erreur de saisie'])->assertOk();
        $this->assertSame([ReliquatClient::RECU_PARTIEL, 24], $etat());

        // Suggestion d'inventaire : 50 unités sous le seuil se commandent en 3 caisses de 24.
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => false, 'avec_commandes' => false])->assertOk();
        $suggestion = collect($this->getJson('/api/suggestions-achat')->assertOk()->json())->firstWhere('source', 'inventaire');
        $this->assertSame(50, $suggestion['quantite_suggeree']);
        $this->postJson('/api/suggestions-achat/accepter', ['ids' => [$suggestion['id']], 'accepte' => true])->assertOk();
        $commandees = $this->postJson('/api/suggestions-achat/commander')->assertCreated()->json('commandes');
        $ligneSuggeree = $this->getJson("/api/commandes-fournisseur/{$commandees[0]['id']}")->assertOk()->json('lignes.0');
        $this->assertSame([3, 'CS', 24], [$ligneSuggeree['quantite_commandee'], $ligneSuggeree['unite_achat'], $ligneSuggeree['qte_par_unite_achat']]);
    }

    public function test_retour_au_fournisseur_en_unites_de_stock(): void
    {
        [$commande, $ligne] = $this->commande($this->brasserie, 2, 1500);
        $this->postJson("/api/commandes-fournisseur/{$commande}/valider")->assertOk();
        $this->recevoir($ligne['id'], 2);

        // Une caisse repart : 24 unités sortent du stock.
        $retour = $this->postJson('/api/retours-fournisseurs', ['commande_fournisseur_id' => $commande, 'type' => 'remboursement', 'date_retour' => '2026-10-17',
            'lignes' => [['com_four_ligne_id' => $ligne['id'], 'quantite' => 1, 'secteur_id' => $this->secteurId]]])->assertCreated()->json();
        $this->postJson("/api/retours-fournisseurs/{$retour['id']}/expedier")->assertOk();
        $sortie = $this->chez(fn () => MouvementTenant::query()->where('type', MouvementTenant::TYPE_SORTIE)->first());
        $this->assertEquals([24, 'UN'], [(float) $sortie->quantite, $sortie->unite_mesure]);
        $this->assertSame(2, $this->chez(fn () => ComFourLigne::find($ligne['id'])->quantite_recue));
    }
}
