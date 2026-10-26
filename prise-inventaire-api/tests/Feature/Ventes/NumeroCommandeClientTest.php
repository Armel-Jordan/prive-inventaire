<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Courriels\VariablesCourriel;
use App\Services\DocumentsVentePdf;
use App\Services\Portail\AccesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Numéro de commande du client (plan de parité fripv2, socle S4.1) : exigé par la fiche du client sur ses commandes et
 * factures (pas sur un client qui ne l'exige pas), repris de la commande à la facture puis au bon de livraison,
 * cherché dans les listes, imprimé, en variable de courriel, et donné par le client dans l'espace client.
 */
class NumeroCommandeClientTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function client(string $code, bool $exige): int
    {
        return $this->postJson('/api/clients', ['raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0',
            'commande_client_obligatoire' => $exige])->assertCreated()->json('id');
    }

    private function commande(int $client, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/commandes-client', $autres + ['client_id' => $client, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100]]]);
    }

    public function test_exige_par_la_fiche_et_repris_jusqu_au_bon_de_livraison(): void
    {
        $exigeant = $this->client('EX', true);
        $libre = $this->client('LI', false);
        $this->getJson("/api/clients/{$exigeant}/fiche")->assertOk()->assertJsonPath('commande_client_obligatoire', true);

        // Commande : exigé pour ce client, facultatif pour l'autre ; transmis vide à la modification, refusé.
        $this->commande($exigeant)->assertUnprocessable()->assertJsonValidationErrors(['numero_commande_client']);
        $this->commande($libre)->assertCreated();
        $commande = $this->commande($exigeant, ['numero_commande_client' => ' PO-4512 '])->assertCreated()->assertJsonPath('numero_commande_client', 'PO-4512')->json('id');
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Fragile'])->assertOk();
        $this->putJson("/api/commandes-client/{$commande}", ['numero_commande_client' => ''])->assertUnprocessable()->assertJsonValidationErrors(['numero_commande_client']);

        // Facture tirée de la commande, puis bon de livraison : la référence suit.
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->assertJsonPath('numero_commande_client', 'PO-4512')->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->assertJsonPath('numero_commande_client', 'PO-4512')->json('id');

        // Facture saisie : exigée aussi.
        $lignes = [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]];
        $this->postJson('/api/factures', ['client_id' => $exigeant, 'date_facture' => '2026-10-16', 'lignes' => $lignes])
            ->assertUnprocessable()->assertJsonValidationErrors(['numero_commande_client']);
        $this->postJson('/api/factures', ['client_id' => $libre, 'date_facture' => '2026-10-16', 'lignes' => $lignes])->assertCreated();

        // Recherche dans les listes.
        $this->assertSame([$commande], array_column($this->getJson('/api/commandes-client?recherche=PO-4512')->assertOk()->json('data'), 'id'));
        $this->assertSame([$facture], array_column($this->getJson('/api/factures?recherche=PO-4512')->assertOk()->json('data'), 'id'));

        // PDF et variable de courriel.
        $this->chez(function () use ($bon) {
            $this->assertSame('PO-4512', app(DocumentsVentePdf::class)->bonLivraison(BonLivraison::findOrFail($bon))['infos']['N° commande client']);
            $this->assertSame('PO-4512', app(VariablesCourriel::class)->pour('bon_livraison', BonLivraison::findOrFail($bon), 'fr')['numero_commande_client']);
        });
    }

    public function test_espace_client(): void
    {
        $exigeant = $this->client('EX', true);
        $jeton = $this->chez(function () use ($exigeant) {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port', 'devise_symbole' => 'FCFA']);
            PortailParametres::create(['actif' => true]);
            Configuration::updateOrCreate(['entite' => 'commande'], ['prefixe' => 'CMD', 'suffixe' => '', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);

            return app(AccesPortail::class)->ouvrirSession(PortailCompte::create(['client_id' => $exigeant, 'nom' => 'Awa', 'email' => 'awa@client.test', 'password' => 'motdepasse1', 'actif' => true]));
        });
        $portail = fn (string $methode, string $uri, array $donnees = []) => $this->withHeaders(['Authorization' => "Bearer {$jeton}"])->json($methode, $uri, $donnees);

        $portail('GET', '/api/espace-client/moi')->assertOk()->assertJsonPath('client.commande_client_obligatoire', true);
        $portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->produitId, 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['numero_commande_client']);
        $id = $portail('POST', '/api/espace-client/commandes', ['lignes' => [['produit_id' => $this->produitId, 'quantite' => 1]], 'numero_commande_client' => 'BC-77'])
            ->assertCreated()->json('commande.id');
        $portail('GET', "/api/espace-client/commandes/{$id}")->assertOk()->assertJsonPath('commande.numero_commande_client', 'BC-77');
        $this->assertSame('BC-77', $this->chez(fn () => ComClientEntete::whereKey($id)->value('numero_commande_client')));
    }
}
