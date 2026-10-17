<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Frais additionnels et de livraison (phase 1.4) : réglage et contrôles, lignes de frais proposées à un document
 * (par document, par ligne, en pourcentage des produits concernés, frais de livraison du client, type de document,
 * devise, frais déjà présent), frais de livraison des clients et droits.
 */
class FraisAdditionnelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> produits par code */
    private array $produits;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->produits, $this->clientId] = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            collect([
                'CIM' => ['Ciment', 100], 'SAC' => ['Sac de sable', 10],
                'MAN' => ['Manutention', 0], 'EMB' => ['Emballage', 0], 'DGR' => ['Matière dangereuse', 0], 'LIV' => ['Livraison', 0],
            ])->map(fn (array $p, string $code) => ProduitTenant::create(['numero' => $code, 'description' => $p[0], 'prix_unitaire' => $p[1]])->id)->all(),
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
        ]);
        $this->agir('admin');
    }

    private function agir(string $role): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    private function frais(array $donnees): TestResponse
    {
        return $this->postJson('/api/frais', $donnees + ['mode' => 'montant', 'application' => 'document']);
    }

    private function calculer(string $document, array $lignes, array $autres = []): array
    {
        return $this->postJson('/api/frais/calculer', $autres + ['document' => $document, 'client_id' => $this->clientId, 'lignes' => $lignes])->assertOk()->json();
    }

    /** Lignes de marchandise : 2 sacs de ciment à 100, 3 sacs de sable à 10. */
    private function marchandise(): array
    {
        return [
            ['produit_id' => $this->produits['CIM'], 'quantite' => 2, 'prix_unitaire_ht' => 100],
            ['produit_id' => $this->produits['SAC'], 'quantite' => 3, 'prix_unitaire_ht' => 10],
        ];
    }

    private function resume(array $proposes): array
    {
        return array_map(fn (array $f) => [$f['libelle'], $f['quantite'], $f['prix_unitaire_ht']], $proposes);
    }

    public function test_reglage_des_frais_et_controles(): void
    {
        $manutention = $this->frais(['libelle' => 'Manutention', 'produit_id' => $this->produits['MAN'], 'valeur' => 5])
            ->assertCreated()->assertJsonPath('repetition', 'document')->assertJsonPath('sur_factures', true)->assertJsonPath('produit.numero', 'MAN')->json('id');

        $this->frais(['libelle' => 'Taxe', 'produit_id' => $this->produits['DGR'], 'mode' => 'pourcentage', 'valeur' => 150])
            ->assertUnprocessable()->assertJsonPath('errors.valeur', ['Un pourcentage ne dépasse pas 100.']);
        $this->frais(['libelle' => 'Dangereux', 'produit_id' => $this->produits['DGR'], 'valeur' => 3, 'application' => 'produits'])
            ->assertUnprocessable()->assertJsonPath('errors.produits', ['Choisissez les produits qui déclenchent ce frais (ou appliquez-le aux matières dangereuses).']);
        $this->frais(['libelle' => 'Dangereux', 'produit_id' => $this->produits['DGR'], 'valeur' => 3, 'application' => 'produits', 'produits' => [$this->produits['DGR']]])
            ->assertUnprocessable()->assertJsonPath('errors.produits', ['Le produit du frais ne peut pas le déclencher lui-même.']);
        $this->frais(['libelle' => 'Nulle part', 'produit_id' => $this->produits['EMB'], 'valeur' => 1, 'sur_devis' => false, 'sur_commandes' => false, 'sur_factures' => false])
            ->assertUnprocessable()->assertJsonPath('errors.sur_factures', ['Choisissez au moins un type de document.']);
        $this->frais(['libelle' => 'Sans produit', 'produit_id' => 999999, 'valeur' => 1])->assertUnprocessable()->assertJsonValidationErrors(['produit_id']);

        // Un pourcentage porte toujours sur l'ensemble des lignes concernées : une ligne de frais par document.
        $pourcentage = $this->frais(['libelle' => 'Assurance', 'produit_id' => $this->produits['EMB'], 'mode' => 'pourcentage', 'valeur' => 2, 'repetition' => 'ligne'])
            ->assertCreated()->assertJsonPath('repetition', 'document')->json('id');
        $this->putJson("/api/frais/{$manutention}", ['libelle' => 'Manutention', 'produit_id' => $this->produits['MAN'], 'mode' => 'montant', 'valeur' => 7.5, 'application' => 'document', 'actif' => false])
            ->assertOk()->assertJsonPath('actif', false)->assertJsonPath('valeur', '7.5000');
        $this->assertSame(['Assurance', 'Manutention'], array_column($this->getJson('/api/frais')->assertOk()->json(), 'libelle'));
        $this->assertSame(['Assurance'], array_column($this->getJson('/api/frais?actifs=1')->json(), 'libelle'));
        $this->deleteJson("/api/frais/{$pourcentage}")->assertOk();
        $this->assertSame(['Manutention'], array_column($this->getJson('/api/frais')->json(), 'libelle'));
    }

    public function test_lignes_de_frais_proposees_a_un_document(): void
    {
        $this->frais(['libelle' => 'Manutention', 'produit_id' => $this->produits['MAN'], 'valeur' => 5])->assertCreated();
        $this->frais(['libelle' => 'Emballage', 'produit_id' => $this->produits['EMB'], 'valeur' => 2, 'repetition' => 'ligne'])->assertCreated();
        // Matière dangereuse : 3 % du ciment seulement, pas sur les devis.
        $this->frais(['libelle' => 'Dangereux', 'produit_id' => $this->produits['DGR'], 'mode' => 'pourcentage', 'valeur' => 3, 'application' => 'produits',
            'produits' => [$this->produits['CIM']], 'sur_devis' => false])->assertCreated();
        $livraison = $this->frais(['libelle' => 'Livraison', 'produit_id' => $this->produits['LIV'], 'valeur' => 1200, 'application' => 'livraison'])->assertCreated()->json('id');
        $this->frais(['libelle' => 'Inactif', 'produit_id' => $this->produits['SAC'], 'valeur' => 9, 'actif' => false])->assertCreated();

        // Sans frais de livraison dans la fiche du client : pas de livraison.
        $this->assertEquals([['Dangereux', 1, 6], ['Emballage', 2, 2], ['Manutention', 1, 5]], $this->resume($this->calculer('facture', $this->marchandise())));
        $this->putJson("/api/clients/{$this->clientId}", ['frais_livraison_id' => $livraison])->assertOk();
        $this->assertEquals([['Dangereux', 1, 6], ['Emballage', 2, 2], ['Livraison', 1, 1200], ['Manutention', 1, 5]], $this->resume($this->calculer('facture', $this->marchandise())));

        // Devis : pas de matière dangereuse. Document en dollars (taux 600) : montants fixes convertis ; un frais qui
        // tombe à 0,00 (2 FCFA) n'est pas proposé.
        $this->assertSame(['Emballage', 'Livraison', 'Manutention'], array_column($this->calculer('devis', $this->marchandise()), 'libelle'));
        $this->assertEquals([['Dangereux', 1, 6], ['Livraison', 1, 2], ['Manutention', 1, 0.01]],
            $this->resume($this->calculer('commande', $this->marchandise(), ['taux_change' => 600])));

        // Frais déjà sur le document (ligne saisie, document modifié) : pas proposé de nouveau, et pas compté comme marchandise.
        $avecManutention = [...$this->marchandise(), ['produit_id' => $this->produits['MAN'], 'quantite' => 1, 'prix_unitaire_ht' => 5]];
        $this->assertEquals([['Dangereux', 1, 6], ['Emballage', 2, 2], ['Livraison', 1, 1200]], $this->resume($this->calculer('facture', $avecManutention)));
        // Remise de ligne : le pourcentage porte sur le montant après remise. Sans marchandise : rien.
        $this->assertEquals([['Dangereux', 1, 3]], array_slice($this->resume($this->calculer('facture', [['produit_id' => $this->produits['CIM'], 'quantite' => 1, 'prix_unitaire_ht' => 200, 'remise_ligne' => 50]])), 0, 1));
        $this->assertSame([], $this->calculer('facture', [['produit_id' => $this->produits['MAN'], 'quantite' => 1, 'prix_unitaire_ht' => 5]]));
        $this->assertSame([], $this->calculer('facture', []));
    }

    public function test_frais_de_livraison_des_clients_et_droits(): void
    {
        $manutention = $this->frais(['libelle' => 'Manutention', 'produit_id' => $this->produits['MAN'], 'valeur' => 5])->json('id');
        $livraison = $this->frais(['libelle' => 'Livraison', 'produit_id' => $this->produits['LIV'], 'valeur' => 15, 'application' => 'livraison'])->json('id');

        // Seul un frais « livraison » se choisit dans la fiche d'un client.
        $this->putJson("/api/clients/{$this->clientId}", ['frais_livraison_id' => $manutention])->assertUnprocessable()->assertJsonValidationErrors(['frais_livraison_id']);
        $this->putJson("/api/clients/{$this->clientId}", ['frais_livraison_id' => $livraison])->assertOk()->assertJsonPath('frais_livraison_id', $livraison);
        // Devenu frais de document, il quitte la fiche ; supprimé aussi.
        $this->putJson("/api/frais/{$livraison}", ['libelle' => 'Livraison', 'produit_id' => $this->produits['LIV'], 'mode' => 'montant', 'valeur' => 15, 'application' => 'document'])->assertOk();
        $this->assertNull($this->getJson("/api/clients/{$this->clientId}")->json('frais_livraison_id'));
        $this->putJson("/api/frais/{$livraison}", ['libelle' => 'Livraison', 'produit_id' => $this->produits['LIV'], 'mode' => 'montant', 'valeur' => 15, 'application' => 'livraison'])->assertOk();
        $this->putJson("/api/clients/{$this->clientId}", ['frais_livraison_id' => $livraison])->assertOk();
        $this->deleteJson("/api/frais/{$livraison}")->assertOk();
        $this->assertNull($this->getJson("/api/clients/{$this->clientId}")->json('frais_livraison_id'));

        // Régler les frais relève de la configuration ; les proposer sur un document est ouvert à qui saisit des ventes.
        $this->agir('user');
        $this->getJson('/api/frais')->assertOk();
        $this->frais(['libelle' => 'Autre', 'produit_id' => $this->produits['EMB'], 'valeur' => 1])->assertForbidden();
        $this->deleteJson("/api/frais/{$manutention}")->assertForbidden();
        $this->assertSame(['Manutention'], array_column($this->calculer('facture', $this->marchandise()), 'libelle'));
    }
}
