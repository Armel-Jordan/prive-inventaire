<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consignation chez le client (phase 1.9) : bon de dépôt numéroté (produits regroupés, coût moyen d'achat), relevé
 * (solde, coût, valeur, dernier dépôt), reprise (lieu obligatoire, pas plus que le solde), consommation facturée aux
 * prix du client, historique, rapport et bons imprimés ; droits et isolation.
 */
class ConsignationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $clients;

    /** @var array<string, int> */
    private array $produits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        [$this->clients, $this->produits] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Gaz du Littoral', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);
            $client = fn (string $code, array $autres = []) => Client::create($autres + ['code' => $code, 'raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $gaz = ProduitTenant::create(['numero' => 'GAZ', 'description' => 'Bouteille de gaz', 'prix_unitaire' => 5000]);
            // Coût d'achat du gaz : 3 000 l'unité (commande reçue) ; le détendeur n'a pas d'achat.
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Gazière'])->id,
                'date_commande' => '2026-09-01', 'statut' => ComFourEntete::STATUT_COMPLETE, 'taux_change' => 1, 'created_by' => $this->admin->id]);
            ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $gaz->id, 'quantite_commandee' => 10, 'quantite_recue' => 10,
                'qte_par_unite_achat' => 1, 'prix_unitaire' => 3000, 'montant_ligne' => 30000]);

            return [
                ['A' => $client('A', ['taux_remise_global' => 5]), 'B' => $client('B')],
                ['GAZ' => $gaz->id, 'DET' => ProduitTenant::create(['numero' => 'DET', 'description' => 'Détendeur', 'prix_unitaire' => 2000])->id],
            ];
        });
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function releve(string $client = 'A'): array
    {
        return $this->getJson('/api/consignations/releve?client_id='.$this->clients[$client])->assertOk()->json();
    }

    public function test_depot_releve_reprise_consommation_et_rapport(): void
    {
        // Bon de dépôt : numéroté, produits regroupés, coût moyen d'achat (inconnu sans achat).
        $depot = $this->postJson('/api/consignations', ['client_id' => $this->clients['A'], 'date_depot' => '2026-10-17', 'lignes' => [
            ['produit_id' => $this->produits['GAZ'], 'quantite' => 10], ['produit_id' => $this->produits['DET'], 'quantite' => 3], ['produit_id' => $this->produits['GAZ'], 'quantite' => 2],
        ]])->assertCreated()->json();
        $this->assertSame('CS-00001', $depot['numero']);
        $this->assertEquals([[$this->produits['GAZ'], 12, 3000], [$this->produits['DET'], 3, null]], array_map(fn ($l) => [$l['produit_id'], $l['quantite'], $l['cout_unitaire']], $depot['lignes']));
        $this->postJson('/api/consignations', ['client_id' => $this->clients['A'], 'date_depot' => '2026-10-17', 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 5]]])->assertCreated();

        // Relevé : solde, coût, valeur au coût, dernier dépôt.
        $releve = $this->releve();
        $this->assertEquals([['DET', 3, null, null, '2026-10-17'], ['GAZ', 17, 3000, 51000, '2026-10-17']],
            array_map(fn ($l) => [$l['produit']['numero'], $l['quantite'], $l['cout_unitaire'], $l['valeur'], $l['dernier_depot']], $releve['lignes']));
        $this->assertEquals([20, 51000], [$releve['quantite'], $releve['valeur']]);

        // Reprise : lieu obligatoire, pas plus que le solde.
        $this->postJson('/api/consignations/reprise', ['client_id' => $this->clients['A'], 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lieu_retour' => 'Choisissez le lieu où la marchandise revient.']);
        $this->postJson('/api/consignations/reprise', ['client_id' => $this->clients['A'], 'lieu_retour' => 'Dépôt central', 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 4]]])
            ->assertOk()->assertJsonPath('message', '4 unité(s) reprise(s).');
        $this->postJson('/api/consignations/reprise', ['client_id' => $this->clients['A'], 'lieu_retour' => 'Dépôt central', 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 20]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Quantité trop grande pour GAZ : le client en détient 13.']);
        $this->postJson('/api/consignations/reprise', ['client_id' => $this->clients['B'], 'lieu_retour' => 'Dépôt central', 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Quantité trop grande pour GAZ : le client en détient 0.']);

        // Consommation : facture brouillon aux prix du client (remise de 5 %), solde déduit.
        $this->postJson('/api/consignations/facturer', ['client_id' => $this->clients['A'], 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Saisissez au moins une quantité.']);
        $facture = $this->postJson('/api/consignations/facturer', ['client_id' => $this->clients['A'], 'lignes' => [
            ['produit_id' => $this->produits['GAZ'], 'quantite' => 3], ['produit_id' => $this->produits['DET'], 'quantite' => 1],
        ]])->assertCreated()->json('facture');
        $lignes = $this->getJson("/api/factures/{$facture['id']}")->assertOk()->assertJsonPath('statut', 'brouillon')->json('lignes');
        $this->assertEqualsCanonicalizing([[$this->produits['GAZ'], 3, 4750.0, 'Marchandise en consignation consommée'], [$this->produits['DET'], 1, 1900.0, 'Marchandise en consignation consommée']],
            array_map(fn ($l) => [$l['produit_id'], $l['quantite'], (float) $l['prix_unitaire_ht'], $l['description']], $lignes));
        $this->assertSame([2, 10], array_column($this->releve()['lignes'], 'quantite'));

        // Historique : six mouvements, le plus récent d'abord ; la consommation porte sa facture.
        $historique = $this->getJson('/api/consignations/historique?client_id='.$this->clients['A'])->assertOk()->json();
        $this->assertCount(6, $historique);
        $this->assertSame(['consommation', $facture['numero']], [$historique[0]['type'], $historique[0]['facture']['numero']]);
        $this->assertEqualsCanonicalizing(['depot', 'depot', 'depot', 'retour', 'consommation', 'consommation'], array_column($historique, 'type'));
        $this->getJson('/api/consignations/historique?client_id='.$this->clients['A'].'&du=2026-10-17&au=2026-10-01')->assertUnprocessable()
            ->assertJsonValidationErrors(['au' => 'La date de début est postérieure à la date de fin : corrigez la période.']);

        // Rapport (valeur au coût) et documents imprimés ; liste des bons de dépôt.
        $rapport = $this->getJson('/api/consignations/rapport')->assertOk()->json();
        $this->assertEquals(['Tous les clients', 12, 30000], [$rapport['filtre'], $rapport['quantite'], $rapport['valeur']]);
        $this->assertSame(['A'], array_map(fn ($b) => $b['client']['code'], $rapport['clients']));
        $this->assertStringStartsWith('%PDF', $this->get('/api/consignations/rapport?format=pdf')->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $this->get("/api/consignations/{$depot['id']}/pdf")->assertOk()->getContent());
        $this->assertSame(2, $this->getJson('/api/consignations')->assertOk()->json('total'));
    }

    public function test_droits_et_isolation(): void
    {
        $depot = $this->postJson('/api/consignations', ['client_id' => $this->clients['A'], 'date_depot' => '2026-10-17',
            'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 5]]])->assertCreated()->json('id');

        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $refuser = function (array $exceptions) use ($vendeur) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($vendeur->fresh());
        };
        $consommer = ['client_id' => $this->clients['A'], 'lignes' => [['produit_id' => $this->produits['GAZ'], 'quantite' => 1]]];
        $refuser(['consignations.facturer' => false, 'consignations.modifier' => false]);
        $this->postJson('/api/consignations/facturer', $consommer)->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Facturer la consommation en consignation » (Consignations).');
        $this->postJson('/api/consignations/reprise', $consommer + ['lieu_retour' => 'Dépôt'])->assertForbidden();
        $this->getJson('/api/consignations/releve?client_id='.$this->clients['A'])->assertOk();
        $refuser(['factures.creer' => false]);
        $this->postJson('/api/consignations/facturer', $consommer)->assertForbidden()->assertJsonPath('action', 'creer');
        $refuser([]);
        $this->postJson('/api/consignations/facturer', $consommer)->assertCreated();

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['consignations.facturer'], array_slice(array_column($modules['consignations']['permissions'], 'code'), 4));

        // Une autre entreprise ne voit ni les bons ni les soldes.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame(0, $this->getJson('/api/consignations')->assertOk()->json('total'));
        $this->getJson("/api/consignations/{$depot}")->assertNotFound();
        $this->get("/api/consignations/{$depot}/pdf")->assertNotFound();
        $this->getJson('/api/consignations/releve?client_id='.$this->clients['A'])->assertUnprocessable();
        $this->assertSame([], $this->getJson('/api/consignations/rapport')->assertOk()->json('clients'));
    }
}
