<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\Transporteur;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Option « transporteur obligatoire » (plan de parité fripv2, phase 1.11b) : inactive par défaut et sans effet tant que
 * l'entreprise n'a aucun transporteur actif ; active, un devis, une commande, une facture ou une commande fournisseur
 * saisis doivent avoir un transporteur (celui du client ou du fournisseur compte), jamais la fiche d'un client.
 */
class TransporteurObligatoireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function facture(int $client, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/factures', $autres + ['client_id' => $client, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]]]);
    }

    public function test_un_document_saisi_doit_avoir_un_transporteur(): void
    {
        [$sansTransporteur, $avecTransporteur, $dhl] = $this->chez(function () {
            $dhl = Transporteur::create(['nom' => 'DHL Express', 'actif' => true]);
            $client = fn (string $code, ?int $transporteur) => Client::create(['code' => $code, 'raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue',
                'ville' => 'Douala', 'code_postal' => '0', 'transporteur_id' => $transporteur])->id;

            return [$client('C1', null), $client('C2', $dhl->id), $dhl->id];
        });

        // Option inactive : rien d'exigé.
        $this->facture($sansTransporteur)->assertCreated();
        $this->putJson('/api/parametres', ['transporteur_obligatoire' => true])->assertOk()->assertJsonPath('parametres.transporteur_obligatoire', true);

        // Active : exigé, sauf si le client en a un (repris) ; transmis, il ne peut pas être vide.
        $this->facture($sansTransporteur)->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);
        $this->facture($sansTransporteur, ['transporteur_id' => $dhl])->assertCreated();
        $this->facture($avecTransporteur)->assertCreated()->assertJsonPath('transporteur_id', $dhl);
        $this->facture($avecTransporteur, ['transporteur_id' => null])->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);
        $this->postJson('/api/devis', ['client_id' => $sansTransporteur, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);

        // Modification : non transmis, celui du document reste ; transmis vide, refusé.
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $avecTransporteur, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Fragile'])->assertOk();
        $this->putJson("/api/commandes-client/{$commande}", ['transporteur_id' => null])->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);

        // La fiche d'un client n'est pas un document : son transporteur reste facultatif.
        $this->putJson("/api/clients/{$avecTransporteur}", ['transporteur_id' => null])->assertOk();

        // Commande fournisseur : le transporteur du fournisseur compte.
        [$fournisseurSans, $fournisseurAvec] = $this->chez(fn () => [
            Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam', 'actif' => true, 'devise' => 'XAF'])->id,
            Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Fer du Sud', 'actif' => true, 'devise' => 'XAF', 'transporteur_id' => $dhl])->id,
        ]);
        $commandeFournisseur = fn (int $fournisseur) => $this->postJson('/api/commandes-fournisseur', ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite_commandee' => 5, 'prix_unitaire' => 80]]]);
        $commandeFournisseur($fournisseurSans)->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);
        $commandeFournisseur($fournisseurAvec)->assertCreated();

        // Plus aucun transporteur actif : l'option ne bloque plus rien.
        $this->chez(fn () => Transporteur::query()->update(['actif' => false]));
        $this->facture($sansTransporteur)->assertCreated();
    }
}
