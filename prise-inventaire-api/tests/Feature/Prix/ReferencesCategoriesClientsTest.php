<?php

namespace Tests\Feature\Prix;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientCategorie;
use App\Models\Devis;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Services\Portail\CommandesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prix et clients (plan de parité fripv2, phase 1.2c) : une règle de prix vise une catégorie de clients (et une
 * catégorie utilisée ne se supprime pas) ; un client a ses références pour les produits (une par produit, une
 * référence ne désigne qu'un produit), reprises sur les PDF de ses documents et dans son espace client ; droits et
 * isolation des entreprises.
 */
class ReferencesCategoriesClientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private ProduitTenant $ciment;

    private ProduitTenant $fer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        [$this->ciment, $this->fer] = $this->chez(fn () => [
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'categorie' => 'Liants', 'prix_unitaire' => 100, 'actif' => true]),
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton 10', 'categorie' => 'Aciers', 'prix_unitaire' => 50, 'actif' => true]),
        ]);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function client(string $code, string $nom, ?int $categorie = null): Client
    {
        return $this->chez(fn () => Client::create([
            'code' => $code, 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'categorie_id' => $categorie,
        ]));
    }

    private function prix(?Client $client, ProduitTenant $produit): array
    {
        return $this->postJson('/api/prix/calculer', array_filter([
            'client_id' => $client?->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]))->assertOk()->json(0);
    }

    public function test_une_regle_de_prix_vise_une_categorie_de_clients(): void
    {
        [$grossistes, $detaillants] = $this->chez(fn () => [
            ClientCategorie::create(['nom' => 'Grossistes', 'actif' => true])->id,
            ClientCategorie::create(['nom' => 'Détaillants', 'actif' => true])->id,
        ]);
        $grossiste = $this->client('G1', 'Quincaillerie en gros', $grossistes);
        $detaillant = $this->client('D1', 'Boutique du coin', $detaillants);
        $sansCategorie = $this->client('S1', 'Client sans catégorie');

        $regle = $this->postJson('/api/regles-prix', [
            'libelle' => 'Remise grossistes', 'categorie_client_id' => $grossistes, 'type' => 'remise_pourcentage', 'valeur' => 10,
        ])->assertCreated()->assertJsonPath('categorie_client.nom', 'Grossistes')->json();

        $remise = $this->prix($grossiste, $this->ciment);
        $this->assertSame([90.0, 'regle', 'Remise grossistes'], [(float) $remise['prix'], $remise['origine'], $remise['regle']['libelle']]);
        $this->assertSame([100.0, 100.0, 100.0], [(float) $this->prix($detaillant, $this->ciment)['prix'], (float) $this->prix($sansCategorie, $this->ciment)['prix'], (float) $this->prix(null, $this->ciment)['prix']]);
        // Combinée à un produit, la règle ne vaut que pour lui.
        $this->putJson("/api/regles-prix/{$regle['id']}", [
            'libelle' => 'Remise grossistes', 'categorie_client_id' => $grossistes, 'produit_id' => $this->fer->id, 'type' => 'remise_pourcentage', 'valeur' => 10,
        ])->assertOk();
        $this->assertSame([100.0, 45.0], [(float) $this->prix($grossiste, $this->ciment)['prix'], (float) $this->prix($grossiste, $this->fer)['prix']]);

        // Une catégorie utilisée par une règle ne se supprime pas (la règle vaudrait sinon pour tous les clients).
        $this->deleteJson("/api/categories-clients/{$grossistes}")->assertUnprocessable()
            ->assertJsonPath('message', "Cette catégorie est utilisée par 1 règle(s) de prix : changez-les ou supprimez-les d'abord (Tarifs & Prix).");
        $this->deleteJson("/api/categories-clients/{$detaillants}")->assertOk();

        // Catégorie d'une autre entreprise : refusée.
        $autre = Tenant::factory()->create();
        $etrangere = app(TenantContext::class)->runAsTenant($autre->id, fn () => ClientCategorie::create(['nom' => 'Ailleurs', 'actif' => true])->id);
        $this->postJson('/api/regles-prix', ['libelle' => 'X', 'categorie_client_id' => $etrangere, 'type' => 'remise_pourcentage', 'valeur' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['categorie_client_id']);
    }

    public function test_un_client_a_ses_references_pour_les_produits(): void
    {
        $client = $this->client('C1', 'Quincaillerie Mballa');
        $autreClient = $this->client('C2', 'Boutique du coin');
        $adresse = "/api/clients/{$client->id}/references-produits";

        $reference = $this->postJson($adresse, ['produit_id' => $this->ciment->id, 'reference' => '  ART-501 ', 'description' => 'Ciment gris'])
            ->assertCreated()->assertJsonPath('reference', 'ART-501')->assertJsonPath('produit.numero', 'CIM-50')->json();
        // Une référence par produit, et une référence ne désigne qu'un produit chez ce client.
        $this->postJson($adresse, ['produit_id' => $this->ciment->id, 'reference' => 'ART-999'])->assertUnprocessable()
            ->assertJsonPath('errors.produit_id.0', 'Ce produit a déjà une référence chez ce client.');
        $this->postJson($adresse, ['produit_id' => $this->fer->id, 'reference' => 'ART-501'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Cette référence désigne déjà un autre produit chez ce client.');
        // Un autre client peut utiliser la même référence.
        $this->postJson("/api/clients/{$autreClient->id}/references-produits", ['produit_id' => $this->ciment->id, 'reference' => 'ART-501'])->assertCreated();

        $this->putJson("{$adresse}/{$reference['id']}", ['produit_id' => $this->ciment->id, 'reference' => 'ART-502', 'description' => ''])
            ->assertOk()->assertJsonPath('reference', 'ART-502')->assertJsonPath('description', null);
        $this->postJson($adresse, ['produit_id' => $this->fer->id, 'reference' => 'ART-777'])->assertCreated();
        $this->assertSame(['ART-502', 'ART-777'], array_column($this->getJson($adresse)->assertOk()->json(), 'reference'));
        $this->getJson("/api/clients/{$client->id}/fiche")->assertOk()->assertJsonPath('references_count', 2);

        // Droits : il faut le droit de modifier les clients (aussi pour en retirer une, sans celui de les supprimer).
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['clients.voir' => true, 'clients.modifier' => false]])->assertOk();
        $this->agir($vendeur);
        $this->postJson($adresse, ['produit_id' => $this->fer->id, 'reference' => 'ART-888'])->assertForbidden();
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['clients.voir' => true, 'clients.modifier' => true, 'clients.supprimer' => false]])->assertOk();
        $this->agir($vendeur);
        $this->deleteJson("{$adresse}/{$reference['id']}")->assertOk();
        $this->assertSame(['ART-777'], array_column($this->getJson($adresse)->assertOk()->json(), 'reference'));

        // Une autre entreprise ne voit ni ne touche les références de ce client.
        $this->agir(AdminUser::factory()->for(Tenant::factory()->create(['modules' => ['ventes']]))->create(['role' => 'admin']));
        $this->getJson($adresse)->assertNotFound();
        $this->postJson($adresse, ['produit_id' => $this->fer->id, 'reference' => 'X'])->assertNotFound();
    }

    public function test_la_reference_du_client_figure_sur_ses_documents_et_dans_son_espace(): void
    {
        $client = $this->client('C1', 'Quincaillerie Mballa');
        $this->postJson("/api/clients/{$client->id}/references-produits", ['produit_id' => $this->ciment->id, 'reference' => 'ART-501'])->assertCreated();

        // PDF d'un devis : la référence du client sous la désignation du produit qui en a une.
        $devis = $this->chez(function () use ($client) {
            $devis = Devis::create(['numero' => 'DEV-0001', 'client_id' => $client->id, 'date_devis' => '2026-10-15', 'date_validite' => '2026-11-15', 'statut' => Devis::STATUT_BROUILLON]);
            $devis->lignes()->create(['produit_id' => $this->ciment->id, 'quantite' => 2, 'prix_unitaire' => 100, 'montant_ligne' => 200]);
            $devis->lignes()->create(['produit_id' => $this->fer->id, 'quantite' => 1, 'prix_unitaire' => 50, 'montant_ligne' => 50]);

            return $devis;
        });
        $lignes = $this->chez(fn () => app(DocumentsVentePdf::class)->devis($devis)['lignes']);
        $this->assertSame(["Ciment 50 kg\nRéf. client : ART-501", 'Fer à béton 10'], array_column($lignes, 'designation'));

        // Espace client : sa référence avec chaque produit, et la recherche par sa référence.
        $catalogue = $this->chez(fn () => app(CommandesPortail::class)->catalogue($client, null, null, 1));
        $this->assertSame(['CIM-50' => 'ART-501', 'FER-10' => null], collect($catalogue['data'])->pluck('reference_client', 'numero')->all());
        $this->assertSame(['CIM-50'], collect($this->chez(fn () => app(CommandesPortail::class)->catalogue($client, 'ART-5', null, 1))['data'])->pluck('numero')->all());
        $panier = $this->chez(fn () => app(CommandesPortail::class)->prix($client, [['produit_id' => $this->ciment->id, 'quantite' => 3]]));
        $this->assertSame('ART-501', $panier[0]['reference_client']);
    }
}
