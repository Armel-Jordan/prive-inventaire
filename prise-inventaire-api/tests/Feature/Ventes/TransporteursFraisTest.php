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
use App\Models\Transporteur;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Frais des transporteurs (plan de parité fripv2, phase 1.11b, options 15 et 21) : un transporteur qui ne facture pas
 * la livraison empêche la proposition du frais de livraison du client ; le frais d'achat d'un transporteur est proposé
 * en « Frais de transport » sur les factures des fournisseurs qu'il livre (depuis des réceptions ou à la main), jamais
 * dans une autre devise ; le frais retiré quitte la fiche du transporteur.
 */
class TransporteursFraisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $produits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            foreach (['CIM' => 'Ciment 50 kg', 'LIV' => 'Livraison', 'TRA' => 'Transport'] as $numero => $description) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => 100])->id;
            }
        });
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function fraisLivraison(string $libelle, string $produit, float $valeur, string $mode = 'montant'): int
    {
        return $this->postJson('/api/frais', ['libelle' => $libelle, 'produit_id' => $this->produits[$produit], 'mode' => $mode, 'valeur' => $valeur, 'application' => 'livraison'])
            ->assertCreated()->json('id');
    }

    private function transporteur(string $nom, array $autres = []): int
    {
        return $this->postJson('/api/referentiels/transporteurs', $autres + ['nom' => $nom, 'actif' => true])->assertCreated()->json('id');
    }

    public function test_un_transporteur_qui_ne_facture_pas_la_livraison_bloque_le_frais_du_client(): void
    {
        $livraison = $this->fraisLivraison('Livraison Douala', 'LIV', 2000);
        $client = $this->chez(fn () => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala',
            'code_postal' => '0', 'frais_livraison_id' => $livraison])->id);
        $facture = $this->transporteur('DHL Express');
        $offert = $this->transporteur('Livreur maison', ['frais_livraison_applicable' => false]);
        $this->getJson('/api/referentiels/transporteurs')->assertOk()->assertJsonPath('0.frais_livraison_applicable', true);
        $calculer = fn (?int $transporteur) => array_column($this->postJson('/api/frais/calculer', ['document' => 'facture', 'client_id' => $client, 'transporteur_id' => $transporteur,
            'lignes' => [['produit_id' => $this->produits['CIM'], 'quantite' => 2, 'prix_unitaire_ht' => 100]]])->assertOk()->json(), 'frais_id');

        // Sans transporteur ou avec un transporteur qui facture la livraison : le frais du client ; sinon rien.
        $this->assertSame([$livraison], $calculer(null));
        $this->assertSame([$livraison], $calculer($facture));
        $this->assertSame([], $calculer($offert));
    }

    public function test_frais_de_transport_propose_sur_la_facture_du_fournisseur(): void
    {
        $transport = $this->fraisLivraison('Fret Cimencam', 'TRA', 5000);
        $dhl = $this->transporteur('DHL Express', ['frais_livraison_achat_id' => $transport]);
        $sansFrais = $this->transporteur('Gozem');
        [$fournisseur, $autre, $enDollars] = $this->chez(fn () => [
            Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam', 'actif' => true, 'devise' => 'XAF', 'transporteur_id' => $dhl]),
            Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Quincaillerie du Port', 'actif' => true, 'devise' => 'XAF', 'transporteur_id' => $sansFrais]),
            Fournisseur::create(['code' => 'F3', 'raison_sociale' => 'Import USA', 'actif' => true, 'devise' => 'USD', 'transporteur_id' => $dhl]),
        ]);

        // Depuis une réception : une ligne « Frais de transport » après la marchandise, au montant du frais.
        $ligne = $this->chez(function () use ($fournisseur) {
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'devise' => 'XAF', 'taux_change' => 1, 'created_by' => $this->admin->id]);
            $this->assertSame($fournisseur->transporteur_id, $commande->transporteur_id);

            return ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits['CIM'], 'quantite_commandee' => 10, 'prix_unitaire' => 50])->id;
        });
        $reception = $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-16', 'receptions' => [['com_four_ligne_id' => $ligne, 'quantite_recue' => 10]]])
            ->assertCreated()->json('reception.id');
        $proposition = $this->getJson("/api/factures-fournisseurs/proposition?receptions[]={$reception}")->assertOk()->assertJsonCount(2, 'lignes')->json('lignes.1');
        $this->assertSame([$this->produits['TRA'], 'Frais de transport – DHL Express', 5000.0, true],
            [$proposition['produit_id'], $proposition['description'], (float) $proposition['prix_unitaire'], $proposition['frais_transport']]);

        // À la main : la ligne du transporteur du fournisseur ; rien sans frais d'achat, ni dans une autre devise.
        $this->getJson("/api/factures-fournisseurs/frais-transport?fournisseur_id={$fournisseur->id}")->assertOk()->assertJsonPath('ligne.prix_unitaire', 5000);
        $this->getJson("/api/factures-fournisseurs/frais-transport?fournisseur_id={$fournisseur->id}&devise=XAF")->assertOk()->assertJsonPath('ligne.prix_unitaire', 5000);
        $this->getJson("/api/factures-fournisseurs/frais-transport?fournisseur_id={$autre->id}")->assertOk()->assertJsonPath('ligne', null);
        $this->getJson("/api/factures-fournisseurs/frais-transport?fournisseur_id={$enDollars->id}&devise=USD")->assertOk()->assertJsonPath('ligne', null);

        // Seul un frais de livraison sert de frais de transport ; supprimé, il quitte la fiche du transporteur.
        $document = $this->postJson('/api/frais', ['libelle' => 'Emballage', 'produit_id' => $this->produits['TRA'], 'mode' => 'montant', 'valeur' => 10, 'application' => 'document'])
            ->assertCreated()->json('id');
        $this->putJson("/api/referentiels/transporteurs/{$sansFrais}", ['nom' => 'Gozem', 'frais_livraison_achat_id' => $document])
            ->assertUnprocessable()->assertJsonValidationErrors(['frais_livraison_achat_id']);
        $this->deleteJson("/api/frais/{$transport}")->assertOk();
        $this->assertNull($this->chez(fn () => Transporteur::findOrFail($dhl)->frais_livraison_achat_id));
        $this->getJson("/api/factures-fournisseurs/frais-transport?fournisseur_id={$fournisseur->id}")->assertOk()->assertJsonPath('ligne', null);
    }
}
