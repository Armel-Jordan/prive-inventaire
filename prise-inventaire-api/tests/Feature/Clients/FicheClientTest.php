<?php

namespace Tests\Feature\Clients;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientCategorie;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fiche client complète (plan de parité fripv2, phase 1.1) : synthèse et historique d'achats calculés sur
 * les factures valides, contacts et adresses avec un seul principal, catégories, favoris par utilisateur,
 * remarques, isolation entre entreprises.
 */
class FicheClientTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($this->tenant);
        $this->client = $this->nouveauClient($this->tenant, 'Quincaillerie du Port');
    }

    private function connecter(Tenant $tenant, string $role = 'admin', string $nom = 'Paul Mbarga'): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create(['role' => $role, 'nom' => $nom]);
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function chez(Tenant $tenant, callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $action);
    }

    private function nouveauClient(Tenant $tenant, string $nom): Client
    {
        static $numero = 0;
        $numero++;

        return $this->chez($tenant, fn () => Client::create([
            'code' => "CLI-T{$numero}", 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue du Port',
            'ville' => 'Douala', 'code_postal' => '0', 'actif' => true,
        ]));
    }

    /** Facture et ses lignes [produit, quantité, prix] ; montants HT = TTC pour la lisibilité. */
    private function facture(Client $client, string $numero, string $date, string $statut, array $lignes, float $resteAPayer = 0, ?string $echeance = null): Facture
    {
        return $this->chez($this->tenant, function () use ($client, $numero, $date, $statut, $lignes, $resteAPayer, $echeance) {
            $total = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lignes));
            $facture = Facture::create([
                'numero' => $numero, 'client_id' => $client->id, 'date_facture' => $date, 'date_echeance' => $echeance,
                'statut' => $statut, 'montant_ht' => $total, 'montant_tva' => 0, 'montant_ttc' => $total,
                'montant_paye' => $total - $resteAPayer, 'reste_a_payer' => $resteAPayer,
            ]);
            foreach ($lignes as [$produit, $quantite, $prix]) {
                FactureLigne::create([
                    'facture_id' => $facture->id, 'produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => $prix,
                    'taux_tva' => 0, 'montant_ht' => $quantite * $prix, 'montant_tva' => 0, 'montant_ttc' => $quantite * $prix,
                ]);
            }

            return $facture;
        });
    }

    public function test_synthese_et_historique_d_achats_ne_comptent_que_les_factures_valides(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        [$ciment, $fer] = $this->chez($this->tenant, fn () => [
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100]),
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton 10', 'prix_unitaire' => 50]),
        ]);
        $this->facture($this->client, 'FAC-1', '2026-10-03', 'payee', [[$ciment, 10, 100], [$fer, 2, 50]]);
        $this->facture($this->client, 'FAC-2', '2025-06-10', 'emise', [[$ciment, 5, 100]], 500, '2025-07-10');
        // Ni brouillon, ni annulée, ni facture d'un autre client.
        $this->facture($this->client, 'FAC-3', '2026-10-05', 'brouillon', [[$fer, 100, 50]]);
        $this->facture($this->client, 'FAC-4', '2026-09-05', 'annulee', [[$fer, 100, 50]]);
        $this->facture($this->nouveauClient($this->tenant, 'Autre'), 'FAC-5', '2026-10-01', 'payee', [[$fer, 100, 50]]);

        $synthese = $this->getJson("/api/clients/{$this->client->id}/synthese")->assertOk()->json();

        $this->assertEquals(['total' => 1600, 'annee_courante' => 1100, 'annee_precedente' => 500], $synthese['chiffre_affaires']);
        $this->assertSame(2, $synthese['factures']['nombre']);
        $this->assertEquals(800, $synthese['factures']['panier_moyen']);
        $this->assertSame('2025-06-10', $synthese['factures']['premiere']);
        $this->assertSame(['FAC-1', '2026-10-03'], [$synthese['factures']['derniere']['numero'], $synthese['factures']['derniere']['date']]);
        $this->assertEquals(['montant' => 500, 'maximum' => null, 'en_retard' => ['nombre' => 1, 'montant' => 500]], $synthese['encours']);
        $this->assertCount(12, $synthese['ventes_mensuelles']);
        $this->assertEquals(['mois' => '2026-10', 'montant_ht' => 1100], end($synthese['ventes_mensuelles']));
        $this->assertSame('2025-11', $synthese['ventes_mensuelles'][0]['mois']);
        $this->assertSame(['CIM-50', 'FER-10'], array_column($synthese['produits_les_plus_achetes'], 'numero'));
        $this->assertEquals(['quantite' => 15, 'montant_ht' => 1500, 'factures' => 2, 'dernier_achat' => '2026-10-03'],
            array_intersect_key($synthese['produits_les_plus_achetes'][0], ['quantite' => 1, 'montant_ht' => 1, 'factures' => 1, 'dernier_achat' => 1]));

        $historique = $this->getJson("/api/clients/{$this->client->id}/historique-achats")->assertOk()->json();
        $this->assertSame(3, $historique['total']);
        $this->assertSame(['2026-10-03', '2026-10-03', '2025-06-10'], array_column($historique['data'], 'date'));
        $this->assertEquals(['quantite' => 17, 'montant_ht' => 1600], $historique['totaux']);

        $fers = $this->getJson("/api/clients/{$this->client->id}/historique-achats?recherche=fer")->json();
        $this->assertSame(['Fer à béton 10'], array_column($fers['data'], 'produit_description'));
        $this->assertSame(2, $this->getJson("/api/clients/{$this->client->id}/historique-achats?du=2026-01-01")->json('total'));
    }

    public function test_contacts_et_adresses_avec_un_seul_principal(): void
    {
        $url = "/api/clients/{$this->client->id}/contacts";
        $aline = $this->postJson($url, ['nom' => 'Aline Ndzi', 'fonction' => 'Acheteuse', 'principal' => true])->assertCreated()->json();
        $this->postJson($url, ['nom' => 'Bruno Etoa', 'email' => 'pas-un-courriel'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $bruno = $this->postJson($url, ['nom' => 'Bruno Etoa', 'email' => 'bruno@exemple.cm', 'principal' => true, 'recoit_factures' => true])->assertCreated()->json();

        $contacts = $this->getJson($url)->assertOk()->json();
        $this->assertSame([['Bruno Etoa', true], ['Aline Ndzi', false]], array_map(fn ($c) => [$c['nom'], $c['principal']], $contacts));

        $this->putJson("{$url}/{$aline['id']}", ['nom' => 'Aline Ndzi', 'telephone' => '699 00 00 00'])->assertOk()->assertJsonPath('telephone', '699 00 00 00');
        $this->deleteJson("{$url}/{$bruno['id']}")->assertOk();
        $this->assertSame(['Aline Ndzi'], array_column($this->getJson($url)->json(), 'nom'));

        // Un contact se manipule seulement par la fiche de son client.
        $autre = $this->nouveauClient($this->tenant, 'Autre');
        $this->putJson("/api/clients/{$autre->id}/contacts/{$aline['id']}", ['nom' => 'X'])->assertNotFound();

        $adresses = "/api/clients/{$this->client->id}/adresses";
        $this->postJson($adresses, ['libelle' => 'Chantier Bonabéri'])->assertUnprocessable()->assertJsonValidationErrors('adresse');
        $this->postJson($adresses, ['libelle' => 'Chantier Bonabéri', 'adresse' => 'Route de Bonabéri', 'principale' => true, 'instructions' => 'Livrer avant 10 h'])->assertCreated();
        $this->postJson($adresses, ['libelle' => 'Entrepôt Akwa', 'adresse' => 'Bd de la Liberté', 'principale' => true])->assertCreated();
        $this->assertSame([['Entrepôt Akwa', true], ['Chantier Bonabéri', false]], array_map(fn ($a) => [$a['libelle'], $a['principale']], $this->getJson($adresses)->json()));

        $fiche = $this->getJson("/api/clients/{$this->client->id}/fiche")->assertOk()->json();
        $this->assertSame([1, 2, 0], [$fiche['contacts_count'], $fiche['adresses_count'], $fiche['remarques_count']]);
    }

    public function test_categories_de_clients(): void
    {
        $grossiste = $this->postJson('/api/categories-clients', ['nom' => 'Grossiste'])->assertCreated()->json();
        $this->postJson('/api/categories-clients', ['nom' => 'Grossiste'])->assertUnprocessable()
            ->assertJsonValidationErrors(['nom' => 'Une catégorie porte déjà ce nom.']);
        $this->nouveauClient($this->tenant, 'Sans catégorie');

        $this->putJson("/api/clients/{$this->client->id}/categorie", ['categorie_id' => $grossiste['id']])->assertOk()->assertJsonPath('categorie.nom', 'Grossiste');
        $this->assertSame(['Quincaillerie du Port'], array_column($this->getJson("/api/clients?categorie_id={$grossiste['id']}")->json('data'), 'raison_sociale'));
        $this->assertSame(1, $this->getJson('/api/categories-clients')->json('0.clients_count'));

        // Une catégorie d'une autre entreprise est refusée.
        $etrangere = $this->chez(Tenant::factory()->create(), fn () => ClientCategorie::create(['nom' => 'Ailleurs']));
        $this->putJson("/api/clients/{$this->client->id}/categorie", ['categorie_id' => $etrangere->id])->assertUnprocessable();

        // Un utilisateur classe ses clients mais ne gère pas les catégories.
        $this->connecter($this->tenant, 'user');
        $this->postJson('/api/categories-clients', ['nom' => 'Détaillant'])->assertForbidden();
        $this->putJson("/api/clients/{$this->client->id}/categorie", ['categorie_id' => null])->assertOk()->assertJsonPath('categorie', null);
        $this->putJson("/api/clients/{$this->client->id}/categorie", ['categorie_id' => $grossiste['id']])->assertOk();

        // Supprimer la catégorie garde le client, sans catégorie.
        $this->connecter($this->tenant);
        $this->deleteJson("/api/categories-clients/{$grossiste['id']}")->assertOk();
        $this->assertNull($this->client->fresh()->categorie_id);
    }

    public function test_les_favoris_sont_propres_a_chaque_utilisateur(): void
    {
        $autre = $this->nouveauClient($this->tenant, 'Autre client');
        $this->postJson("/api/clients/{$this->client->id}/favori")->assertOk()->assertJson(['favori' => true]);
        $this->postJson("/api/clients/{$this->client->id}/favori")->assertOk();

        $this->assertSame(['Quincaillerie du Port'], array_column($this->getJson('/api/clients?favoris=1')->json('data'), 'raison_sociale'));
        $liste = collect($this->getJson('/api/clients')->json('data'))->pluck('favori', 'raison_sociale')->all();
        $this->assertSame(['Autre client' => false, 'Quincaillerie du Port' => true], $liste);
        $this->assertTrue($this->getJson("/api/clients/{$this->client->id}/fiche")->json('favori'));

        $this->connecter($this->tenant, 'user', 'Collègue');
        $this->assertSame([], $this->getJson('/api/clients?favoris=1')->json('data'));
        $this->assertFalse($this->getJson("/api/clients/{$this->client->id}/fiche")->json('favori'));

        $this->deleteJson("/api/clients/{$autre->id}/favori")->assertOk()->assertJson(['favori' => false]);
    }

    public function test_remarques_modifiables_par_leur_auteur_ou_un_administrateur(): void
    {
        $url = "/api/clients/{$this->client->id}/remarques";
        $this->connecter($this->tenant, 'user', 'Aline');
        $premiere = $this->postJson($url, ['texte' => 'Préfère être appelé le matin.'])->assertCreated()->assertJsonPath('auteur.nom', 'Aline')->json();
        $this->postJson($url, ['texte' => 'Paiement à la livraison uniquement.', 'epinglee' => true])->assertCreated();
        $this->postJson($url, ['texte' => ''])->assertUnprocessable();

        $this->assertSame(['Paiement à la livraison uniquement.', 'Préfère être appelé le matin.'], array_column($this->getJson($url)->json(), 'texte'));

        $this->connecter($this->tenant, 'user', 'Bruno');
        $this->putJson("{$url}/{$premiere['id']}", ['texte' => 'Modifié'])->assertForbidden();
        $this->deleteJson("{$url}/{$premiere['id']}")->assertForbidden();

        $this->connecter($this->tenant, 'admin', 'Responsable');
        $this->putJson("{$url}/{$premiere['id']}", ['epinglee' => true])->assertOk()->assertJsonPath('epinglee', true);
        $this->deleteJson("{$url}/{$premiere['id']}")->assertOk();
    }

    public function test_une_autre_entreprise_n_accede_pas_a_la_fiche(): void
    {
        $this->postJson("/api/clients/{$this->client->id}/contacts", ['nom' => 'Aline'])->assertCreated();

        $this->connecter(Tenant::factory()->create(['modules' => ['ventes']]));
        foreach (['fiche', 'synthese', 'historique-achats', 'contacts', 'adresses', 'remarques'] as $onglet) {
            $this->getJson("/api/clients/{$this->client->id}/{$onglet}")->assertNotFound();
        }
        $this->postJson("/api/clients/{$this->client->id}/favori")->assertNotFound();
        $this->postJson("/api/clients/{$this->client->id}/remarques", ['texte' => 'x'])->assertNotFound();
    }
}
