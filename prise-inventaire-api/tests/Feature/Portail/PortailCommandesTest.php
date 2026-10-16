<?php

namespace Tests\Feature\Portail;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\Notification;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Portail\AccesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portail client (phase 1.11), commandes : catalogue au prix du client, prix du panier, commande en attente
 * de validation, suivi, annulation, factures et PDF, limités au client du compte connecté.
 */
class PortailCommandesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $ids = [];

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['slug' => 'quincaillerie', 'modules' => ['ventes']]);
        $this->ids = $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port', 'devise_symbole' => 'FCFA', 'tva_taux' => 19.25]);
            PortailParametres::create(['actif' => true]);
            $client = fn (string $code, float $remise) => Client::create(['code' => $code, 'raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true, 'taux_remise_global' => $remise])->id;
            $ids = ['client' => $client('C-1', 10), 'autre' => $client('C-2', 0)];
            $ids['adresse'] = ClientAdresse::create(['client_id' => $ids['client'], 'libelle' => 'Chantier', 'adresse' => '12 avenue de Gaulle', 'ville' => 'Douala', 'code_postal' => '0', 'livraison' => true, 'actif' => true])->id;
            $produit = fn (string $numero, string $description, ?float $prix, string $categorie, bool $actif = true) => ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => $prix, 'categorie' => $categorie, 'mesure' => 'sac', 'actif' => $actif])->id;
            $ids['ciment'] = $produit('CIM-50', 'Ciment 50 kg', 6000, 'Liants');
            $ids['fer'] = $produit('FER-10', 'Fer à béton 10', 5000, 'Aciers');
            $ids['sable'] = $produit('SAB-01', 'Sable', null, 'Liants');
            $ids['ancien'] = $produit('OLD-01', 'Ancien produit', 100, 'Liants', false);
            $compte = PortailCompte::create(['client_id' => $ids['client'], 'nom' => 'Awa', 'email' => 'awa@littoral.test', 'password' => 'motdepasse1', 'actif' => true]);
            $this->jeton = app(AccesPortail::class)->ouvrirSession($compte);

            return $ids;
        });
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function portail(string $methode, string $uri, array $donnees = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$this->jeton}"])->json($methode, $uri, $donnees);
    }

    /** Numérotation des commandes client de l'entreprise, automatique ou manuelle. */
    private function numerotation(bool $automatique = true): void
    {
        $this->chez(fn () => Configuration::updateOrCreate(['entite' => 'commande'], ['prefixe' => 'CMD', 'suffixe' => '', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => $automatique, 'prochain_numero' => 1]));
    }

    public function test_catalogue_au_prix_du_client(): void
    {
        $catalogue = $this->portail('GET', '/api/espace-client/catalogue')->assertOk()->json();
        // Produits actifs, au prix du client (remise de 10 %) ; sans prix : pas commandable.
        $this->assertSame([['CIM-50', 5400], ['FER-10', 4500], ['SAB-01', null]], array_map(fn ($p) => [$p['numero'], $p['prix']], $catalogue['data']));
        $this->assertSame(['Aciers', 'Liants'], $catalogue['categories']);
        $this->assertSame(['FER-10'], array_column($this->portail('GET', '/api/espace-client/catalogue?recherche=fer')->json('data'), 'numero'));

        // L'entreprise ne propose que certaines catégories.
        $this->chez(fn () => PortailParametres::first()->update(['categories' => ['Aciers']]));
        $this->assertSame(['FER-10'], array_column($this->portail('GET', '/api/espace-client/catalogue')->json('data'), 'numero'));

        $prix = $this->portail('POST', '/api/espace-client/prix', ['lignes' => [['produit_id' => $this->ids['fer'], 'quantite' => 3], ['produit_id' => $this->ids['ciment'], 'quantite' => 1]]])->assertOk()->json('lignes');
        $this->assertSame([[4500, 13500, false], [null, null, true]], array_map(fn ($l) => [$l['prix'], $l['montant'], $l['indisponible']], $prix));
    }

    public function test_commande_en_attente_de_validation(): void
    {
        $panier = ['lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 10], ['produit_id' => $this->ids['fer'], 'quantite' => 2]],
            'adresse_livraison_id' => $this->ids['adresse'], 'date_livraison_souhaitee' => '2026-10-20', 'notes' => 'Livrer le matin.'];

        // Numérotation manuelle des commandes client : le portail ne peut pas numéroter, refus explicite.
        $this->numerotation(false);
        $this->portail('POST', '/api/espace-client/commandes', $panier)->assertUnprocessable()
            ->assertJsonPath('message', 'Les commandes en ligne ne sont pas encore prêtes chez nous : contactez-nous pour passer votre commande.');
        $this->numerotation();
        $this->portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->ids['ancien'], 'quantite' => 1]]])->assertUnprocessable();
        $this->portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->ids['sable'], 'quantite' => 1]]])->assertUnprocessable();

        $creee = $this->portail('POST', '/api/espace-client/commandes', $panier)->assertCreated()->assertJsonPath('commande.statut', 'en_attente')->json('commande');
        $this->assertStringStartsWith('CMD', $creee['numero']);

        $commande = $this->chez(fn () => ComClientEntete::with('lignes')->findOrFail($creee['id']));
        // Prix du client recalculés côté serveur, taux de taxe de l'entreprise (19,25 %).
        $this->assertEquals([[10, 5400, 19.25], [2, 4500, 19.25]], $commande->lignes->map(fn ($l) => [$l->quantite, (float) $l->prix_unitaire_ht, (float) $l->taux_tva])->all());
        $this->assertEquals([63000, 75127.5], [(float) $commande->montant_ht, (float) $commande->montant_ttc]);
        $this->assertSame('2026-10-20', $commande->date_livraison_souhaitee->toDateString());
        $this->assertStringContainsString('Commande passée sur le portail client par Awa (awa@littoral.test).', $commande->notes);
        $this->assertStringContainsString('Livraison : Chantier — 12 avenue de Gaulle, 0 Douala', $commande->notes);
        $this->assertStringContainsString('Livrer le matin.', $commande->notes);
        $notification = $this->chez(fn () => Notification::where('type', 'commande_portail')->first());
        $this->assertStringContainsString("Client C-1 a passé la commande {$creee['numero']} (75 127,50 FCFA)", $notification->message);

        // L'entreprise la voit parmi ses commandes, à accepter ou refuser.
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->getJson('/api/commandes-client?statut=en_attente')->assertOk()->assertJsonPath('data.0.numero', $creee['numero']);
        $this->getJson('/api/portail-clients')->assertOk()->assertJsonPath('commandes_pretes', true);
    }

    public function test_suivi_annulation_et_factures_du_seul_client(): void
    {
        $this->numerotation();
        $premiere = $this->portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->ids['fer'], 'quantite' => 1]]])->json('commande.id');
        $seconde = $this->portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 1]]])->json('commande.id');
        [$autreCommande, $factureClient, $factureAutre] = $this->chez(function () use ($seconde) {
            ComClientEntete::whereKey($seconde)->update(['statut' => 'acceptee']);
            $autre = ComClientEntete::create(['numero' => 'CMD-AUTRE', 'client_id' => $this->ids['autre'], 'date_commande' => '2026-10-10', 'statut' => 'en_attente']);
            $facture = fn (string $numero, int $client, string $statut) => Facture::create(['numero' => $numero, 'client_id' => $client, 'date_facture' => '2026-10-12', 'statut' => $statut, 'montant_ttc' => 100, 'reste_a_payer' => 100])->id;
            $facture('F-BROUILLON', $this->ids['client'], 'brouillon');

            return [$autre->id, $facture('F-1', $this->ids['client'], 'emise'), $facture('F-2', $this->ids['autre'], 'emise')];
        });

        $this->assertCount(2, $this->portail('GET', '/api/espace-client/commandes')->assertOk()->json('data'));
        $this->portail('GET', "/api/espace-client/commandes/{$autreCommande}")->assertNotFound();
        $detail = $this->portail('GET', "/api/espace-client/commandes/{$premiere}")->assertOk()->json('commande');
        $this->assertSame([true, $this->ids['fer'], 'FER-10', 1], [$detail['annulable'], $detail['lignes'][0]['produit_id'], $detail['lignes'][0]['produit']['numero'], $detail['lignes'][0]['quantite']]);

        // Annulable tant qu'elle attend ; plus une fois acceptée.
        $this->portail('POST', "/api/espace-client/commandes/{$premiere}/annuler")->assertOk();
        $this->portail('GET', "/api/espace-client/commandes/{$premiere}")->assertJsonPath('commande.statut', 'annulee');
        $this->portail('POST', "/api/espace-client/commandes/{$seconde}/annuler")->assertUnprocessable();
        $this->portail('GET', "/api/espace-client/commandes/{$seconde}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(['F-1'], array_column($this->portail('GET', '/api/espace-client/factures')->assertOk()->json('data'), 'numero'));
        $this->portail('GET', "/api/espace-client/factures/{$factureClient}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->portail('GET', "/api/espace-client/factures/{$factureAutre}/pdf")->assertNotFound();
    }
}
