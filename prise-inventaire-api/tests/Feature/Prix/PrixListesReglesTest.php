<?php

namespace Tests\Feature\Prix;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prix et remises (plan de parité fripv2, phase 1.2a) : prix de la liste du client, remise globale de la
 * fiche, règles (prix client, paliers, promotions, catégories) avec le plus bas prix retenu, grille de
 * prix, listes, droits et isolation entre entreprises.
 */
class PrixListesReglesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProduitTenant $ciment;

    private ProduitTenant $fer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($this->tenant);
        [$this->ciment, $this->fer] = $this->chez($this->tenant, fn () => [
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'categorie' => 'Liants', 'prix_unitaire' => 100]),
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton 10', 'categorie' => 'Aciers', 'prix_unitaire' => 50]),
        ]);
    }

    private function connecter(Tenant $tenant, string $role = 'admin'): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => $role]), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chez(Tenant $tenant, callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $action);
    }

    private function client(string $nom, array $attributs = []): Client
    {
        static $numero = 0;
        $numero++;

        return $this->chez($this->tenant, fn () => Client::create([
            'code' => "CLI-P{$numero}", 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0',
            ...$attributs,
        ]));
    }

    /** Prix calculé pour un client (ou sans client), un produit et une quantité. */
    private function prix(?Client $client, ProduitTenant $produit, int $quantite = 1, ?string $date = null): array
    {
        return $this->postJson('/api/prix/calculer', array_filter([
            'client_id' => $client?->id, 'date' => $date,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => $quantite]],
        ]))->assertOk()->json(0);
    }

    public function test_prix_de_liste_et_remise_globale_du_client(): void
    {
        $grossiste = $this->postJson('/api/listes-prix', ['nom' => 'Grossiste', 'ajustement_pourcentage' => -10, 'precision' => 2])->assertCreated()->json();
        // Prix saisi pour le fer dans la liste : il remplace la formule.
        $this->putJson('/api/grille-prix', ['modifications' => [['produit_id' => $this->fer->id, 'liste_prix_id' => $grossiste['id'], 'prix' => 42]]])->assertOk();

        $this->assertEquals(['prix' => 100, 'origine' => 'base'], array_intersect_key($this->prix(null, $this->ciment), ['prix' => 1, 'origine' => 1]));

        $client = $this->client('Quincaillerie');
        $this->putJson("/api/clients/{$client->id}/liste-prix", ['liste_prix_id' => $grossiste['id']])->assertOk();
        $this->assertEquals(['prix_liste' => 90, 'prix' => 90, 'origine' => 'liste'], array_intersect_key($this->prix($client, $this->ciment), ['prix_liste' => 1, 'prix' => 1, 'origine' => 1]));
        $this->assertEquals(42, $this->prix($client, $this->fer)['prix']);

        // La remise globale de la fiche client (jusqu'ici jamais appliquée) devient un candidat.
        $remise = $this->client('Remisé', ['taux_remise_global' => 5]);
        $calcul = $this->prix($remise, $this->ciment);
        $this->assertEquals(['prix_liste' => 100, 'prix' => 95, 'origine' => 'remise_client', 'remise_pourcentage' => 5], array_intersect_key($calcul, ['prix_liste' => 1, 'prix' => 1, 'origine' => 1, 'remise_pourcentage' => 1]));

        // Une liste désactivée n'est plus utilisée : retour au prix de base.
        $this->putJson("/api/listes-prix/{$grossiste['id']}", ['nom' => 'Grossiste', 'ajustement_pourcentage' => -10, 'precision' => 2, 'actif' => false])->assertOk();
        $this->assertEquals(100, $this->prix($client, $this->ciment)['prix']);
    }

    public function test_regles_le_plus_bas_prix_l_emporte(): void
    {
        $client = $this->client('Quincaillerie');
        $autre = $this->client('Autre');
        $regle = fn (array $donnees) => $this->postJson('/api/regles-prix', $donnees)->assertCreated()->json();

        $regle(['libelle' => 'Prix négocié ciment', 'client_id' => $client->id, 'produit_id' => $this->ciment->id, 'type' => 'prix_fixe', 'valeur' => 92]);
        $regle(['libelle' => 'Palier 50 sacs', 'produit_id' => $this->ciment->id, 'quantite_min' => 50, 'type' => 'remise_pourcentage', 'valeur' => 15]);
        $regle(['libelle' => 'Promo aciers', 'categorie_produit' => 'aciers', 'date_debut' => '2026-10-01', 'date_fin' => '2026-10-31', 'type' => 'remise_montant', 'valeur' => 5]);
        $regle(['libelle' => 'Promo future', 'produit_id' => $this->fer->id, 'date_debut' => '2026-12-01', 'type' => 'remise_pourcentage', 'valeur' => 50]);
        $regle(['libelle' => 'Règle inactive', 'type' => 'prix_fixe', 'valeur' => 1, 'actif' => false]);

        // Prix négocié pour ce client seulement.
        $calcul = $this->prix($client, $this->ciment, 1, '2026-10-15');
        $this->assertEquals([92, 'regle', 'Prix négocié ciment'], [$calcul['prix'], $calcul['origine'], $calcul['regle']['libelle']]);
        $this->assertEquals(100, $this->prix($autre, $this->ciment, 1, '2026-10-15')['prix']);

        // Le palier de quantité (85) bat le prix négocié (92).
        $this->assertEquals([85, 'Palier 50 sacs'], [($c = $this->prix($client, $this->ciment, 50, '2026-10-15'))['prix'], $c['regle']['libelle']]);

        // Promotion par catégorie (insensible à la casse), seulement pendant sa période ; la promo future est ignorée.
        $this->assertEquals(45, $this->prix($autre, $this->fer, 1, '2026-10-15')['prix']);
        $this->assertEquals(50, $this->prix($autre, $this->fer, 1, '2026-11-15')['prix']);
        $this->assertEquals(25, $this->prix($autre, $this->fer, 1, '2026-12-02')['prix']);

        // Statuts pour la liste des règles.
        $this->travelTo('2026-10-15 09:00:00');
        $libelles = fn (string $statut) => array_column($this->getJson("/api/regles-prix?statut={$statut}")->json('data'), 'libelle');
        $this->assertSame(['Palier 50 sacs', 'Prix négocié ciment', 'Promo aciers'], $libelles('en_vigueur'));
        $this->assertSame(['Promo future'], $libelles('a_venir'));
        $this->assertSame(['Règle inactive'], $libelles('inactive'));
    }

    public function test_une_regle_peut_viser_les_clients_d_une_liste(): void
    {
        $export = $this->postJson('/api/listes-prix', ['nom' => 'Export', 'ajustement_pourcentage' => 0, 'precision' => 0])->assertCreated()->json();
        $client = $this->client('Export SA');
        $this->putJson("/api/clients/{$client->id}/liste-prix", ['liste_prix_id' => $export['id']])->assertOk();
        $this->postJson('/api/regles-prix', ['libelle' => 'Export -20 %', 'liste_prix_id' => $export['id'], 'type' => 'remise_pourcentage', 'valeur' => 20])->assertCreated();

        $this->assertEquals(80, $this->prix($client, $this->ciment)['prix']);
        $this->assertEquals(100, $this->prix($this->client('Local'), $this->ciment)['prix']);

        // La liste visée par une règle ne se supprime pas ; sinon ses clients sont détachés.
        $this->deleteJson("/api/listes-prix/{$export['id']}")->assertUnprocessable();
        $this->assertSame([$client->id], array_column($this->getJson("/api/listes-prix/{$export['id']}/clients")->json(), 'id'));
        $this->assertSame(1, $this->getJson('/api/listes-prix')->json('0.clients_count'));
    }

    public function test_grille_de_prix(): void
    {
        $liste = $this->postJson('/api/listes-prix', ['nom' => 'Détail', 'ajustement_pourcentage' => 20, 'precision' => 0])->assertCreated()->json();
        $this->putJson('/api/grille-prix', ['modifications' => [
            ['produit_id' => $this->ciment->id, 'liste_prix_id' => null, 'prix' => 110.5],
            ['produit_id' => $this->fer->id, 'liste_prix_id' => $liste['id'], 'prix' => 70],
        ]])->assertOk();

        $grille = collect($this->getJson('/api/grille-prix')->assertOk()->json('data'))->keyBy('numero');
        $this->assertEquals(110.5, $grille['CIM-50']['prix_base']);
        $this->assertEquals(['prix' => 133, 'saisi' => false, 'formule' => 133], $grille['CIM-50']['prix'][$liste['id']]);
        $this->assertEquals(['prix' => 70, 'saisi' => true, 'formule' => 60], $grille['FER-10']['prix'][$liste['id']]);
        $this->assertSame(['Aciers', 'Liants'], $this->getJson('/api/grille-prix')->json('categories'));
        $this->assertSame(['FER-10'], array_column($this->getJson('/api/grille-prix?categorie=Aciers')->json('data'), 'numero'));

        // Un prix vide sur une liste retire le prix saisi.
        $this->putJson('/api/grille-prix', ['modifications' => [['produit_id' => $this->fer->id, 'liste_prix_id' => $liste['id'], 'prix' => null]]])->assertOk();
        $this->assertEquals(['prix' => 60, 'saisi' => false, 'formule' => 60], collect($this->getJson('/api/grille-prix')->json('data'))->keyBy('numero')['FER-10']['prix'][$liste['id']]);
    }

    public function test_validation_droits_et_isolation(): void
    {
        $this->postJson('/api/regles-prix', ['libelle' => 'Trop', 'type' => 'remise_pourcentage', 'valeur' => 120])->assertUnprocessable()
            ->assertJsonValidationErrors(['valeur' => 'Une remise ne peut pas dépasser 100 %.']);
        $this->postJson('/api/regles-prix', ['libelle' => 'Dates', 'type' => 'prix_fixe', 'valeur' => 1, 'date_debut' => '2026-10-10', 'date_fin' => '2026-10-01'])->assertUnprocessable()
            ->assertJsonValidationErrors(['date_fin' => 'La fin doit suivre le début.']);
        $this->postJson('/api/listes-prix', ['nom' => 'Détail', 'ajustement_pourcentage' => 0, 'precision' => 2])->assertCreated();
        $this->postJson('/api/listes-prix', ['nom' => 'Détail', 'ajustement_pourcentage' => 0, 'precision' => 2])->assertUnprocessable();

        // Un utilisateur consulte et calcule, mais ne modifie pas les prix.
        $this->connecter($this->tenant, 'user');
        $this->getJson('/api/grille-prix')->assertOk();
        $this->assertEquals(100, $this->prix(null, $this->ciment)['prix']);
        $this->putJson('/api/grille-prix', ['modifications' => [['produit_id' => $this->ciment->id, 'prix' => 1]]])->assertForbidden();
        $this->postJson('/api/regles-prix', ['libelle' => 'X', 'type' => 'prix_fixe', 'valeur' => 1])->assertForbidden();

        // Une autre entreprise ne voit ni ne cible les produits, listes ou clients d'ici.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($autre);
        $this->assertSame([], $this->getJson('/api/listes-prix')->json());
        $this->assertSame(0, $this->getJson('/api/grille-prix')->json('total'));
        $this->postJson('/api/prix/calculer', ['lignes' => [['produit_id' => $this->ciment->id]]])->assertUnprocessable();
        $this->postJson('/api/regles-prix', ['libelle' => 'X', 'produit_id' => $this->ciment->id, 'type' => 'prix_fixe', 'valeur' => 1])->assertUnprocessable();
        $this->putJson('/api/grille-prix', ['modifications' => [['produit_id' => $this->ciment->id, 'prix' => 1]]])->assertUnprocessable();
        $this->assertEquals(100, (float) $this->ciment->fresh()->prix_unitaire);
    }
}
