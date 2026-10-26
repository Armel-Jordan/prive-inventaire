<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\Fournisseur;
use App\Models\PortailCompte;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\Transporteur;
use App\Services\Portail\CommandesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transporteurs (plan de parité fripv2, phase 1.11b) : le transporteur du client passe sur ses devis, commandes,
 * factures et bons de livraison (sauf choix contraire) et suit les conversions ; le numéro de suivi va de la commande à
 * la facture puis au bon ; celui du fournisseur passe sur ses commandes ; filtres des listes, contrôles, référentiel
 * protégé ; isolation des entreprises.
 */
class TransporteursTest extends TestCase
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

    private function transporteur(string $nom): int
    {
        return $this->postJson('/api/referentiels/transporteurs', ['nom' => $nom, 'url_suivi' => 'https://suivi.test/{numero}', 'actif' => true])
            ->assertCreated()->json('id');
    }

    private function client(string $nom, ?int $transporteur): int
    {
        return $this->postJson('/api/clients', [
            'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'transporteur_id' => $transporteur,
        ])->assertCreated()->json('id');
    }

    private function lignesFacture(): array
    {
        return [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]];
    }

    public function test_le_transporteur_du_client_suit_ses_documents_jusqu_au_bon_de_livraison(): void
    {
        $dhl = $this->transporteur('DHL');
        $gozem = $this->transporteur('Gozem');
        $client = $this->client('Quincaillerie Mballa', $dhl);
        $this->getJson("/api/clients/{$client}/fiche")->assertOk()->assertJsonPath('transporteur.nom', 'DHL');

        // Devis sans transporteur demandé : celui du client ; choisi : un autre ; vidé exprès : aucun.
        $devis = $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('transporteur_id', $dhl)->json('id');
        $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'transporteur_id' => $gozem,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('transporteur_id', $gozem);
        $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'transporteur_id' => null,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('transporteur_id', null);

        // Converti en commande : le transporteur suit ; le numéro de suivi se note sur la commande brouillon.
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertOk()->assertJsonPath('transporteur_id', $dhl)->assertJsonPath('transporteur.nom', 'DHL');
        $this->putJson("/api/commandes-client/{$commande}", ['numero_suivi' => 'DHL-123'])->assertOk();
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Fragile'])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('numero_suivi', 'DHL-123');

        // Facturée : transporteur et numéro de suivi suivent, puis passent au bon de livraison.
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $vue = $this->getJson("/api/factures/{$facture}")->assertOk()->json();
        $this->assertSame([$dhl, 'DHL', 'https://suivi.test/{numero}', 'DHL-123'],
            [$vue['transporteur_id'], $vue['transporteur']['nom'], $vue['transporteur']['url_suivi'], $vue['numero_suivi']]);
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->assertSame([$dhl, 'DHL-123'], [$bon['transporteur_id'], $bon['numero_suivi']]);
        $this->getJson("/api/bons-livraison/{$bon['id']}")->assertOk()->assertJsonPath('transporteur.nom', 'DHL');

        // Expédition du bon : transporteur et numéro de suivi se changent ; le colis a son propre numéro.
        $this->putJson("/api/bons-livraison/{$bon['id']}/expedition", ['transporteur_id' => $gozem, 'numero_suivi' => 'GZ-9'])->assertOk()
            ->assertJsonPath('transporteur.nom', 'Gozem')->assertJsonPath('numero_suivi', 'GZ-9');
        $ligne = $this->getJson("/api/bons-livraison/{$bon['id']}")->json('lignes.0.id');
        $colis = $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ligne, 'quantite' => 2]], 'numero_suivi' => 'GZ-9-1'])
            ->assertCreated()->json('colis.0');
        $this->assertSame('GZ-9-1', $colis['numero_suivi']);

        // Un bon retiré par le client n'a pas de transporteur ; un bon livré ne se modifie plus.
        $this->chez(fn () => BonLivraison::whereKey($bon['id'])->update(['mode_livraison' => 'retrait_client']));
        $this->putJson("/api/bons-livraison/{$bon['id']}/expedition", ['transporteur_id' => $dhl])->assertUnprocessable()
            ->assertJsonPath('message', 'Un bon retiré par le client ne passe pas par un transporteur.');
        $this->putJson("/api/bons-livraison/{$bon['id']}/expedition", ['transporteur_id' => null, 'numero_suivi' => null])->assertOk();
        $this->chez(fn () => BonLivraison::whereKey($bon['id'])->update(['statut' => 'livre_complet']));
        $this->putJson("/api/bons-livraison/{$bon['id']}/expedition", ['numero_suivi' => 'X'])->assertUnprocessable();

        // Facture saisie : celui du client, avec son numéro de suivi ; modifiée sans le renvoyer, il reste.
        $saisie = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-17', 'numero_suivi' => 'DHL-456', 'lignes' => $this->lignesFacture()])
            ->assertCreated()->json();
        $this->assertSame([$dhl, 'DHL-456'], [$saisie['transporteur_id'], $saisie['numero_suivi']]);
        $this->putJson("/api/factures/{$saisie['id']}", ['client_id' => $client, 'date_facture' => '2026-10-17', 'transporteur_id' => $gozem, 'lignes' => $this->lignesFacture()])->assertOk();
        $this->assertSame([$gozem, 'DHL-456'], [$this->getJson("/api/factures/{$saisie['id']}")->json('transporteur_id'), $this->getJson("/api/factures/{$saisie['id']}")->json('numero_suivi')]);

        // Commande passée dans l'espace client : le transporteur du client.
        $portail = $this->chez(fn () => app(CommandesPortail::class)->commander(
            PortailCompte::create(['client_id' => $client, 'nom' => 'Acheteur', 'email' => 'acheteur@client.test', 'actif' => true]),
            Client::findOrFail($client), ['lignes' => [['produit_id' => $this->produitId, 'quantite' => 1]]],
        ));
        $this->assertSame($dhl, $portail->transporteur_id);
    }

    public function test_le_transporteur_du_fournisseur_passe_sur_ses_commandes(): void
    {
        $dhl = $this->transporteur('DHL');
        $gozem = $this->transporteur('Gozem');
        $fournisseur = $this->postJson('/api/fournisseurs', ['code' => 'F-1', 'raison_sociale' => 'Cimencam', 'devise' => 'XAF', 'transporteur_id' => $dhl])
            ->assertCreated()->assertJsonPath('transporteur_id', $dhl)->json('id');
        $commande = fn (array $en_plus = []) => $this->postJson('/api/commandes-fournisseur', $en_plus + ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite_commandee' => 5, 'prix_unitaire' => 80]]])->assertCreated()->json('id');

        // Sans transporteur demandé : celui du fournisseur ; choisi : un autre ; vidé exprès : aucun.
        $parDefaut = $commande();
        $this->assertSame($dhl, $this->getJson("/api/commandes-fournisseur/{$parDefaut}")->assertOk()->assertJsonPath('transporteur.nom', 'DHL')->json('transporteur_id'));
        $choisi = $commande(['transporteur_id' => $gozem]);
        $this->assertSame($gozem, $this->chez(fn () => ComFourEntete::findOrFail($choisi)->transporteur_id));
        $this->assertNull($this->chez(fn () => ComFourEntete::findOrFail($commande(['transporteur_id' => null]))->transporteur_id));

        // Créée par un autre chemin (sans valeur) : celui du fournisseur aussi.
        $autre = $this->chez(fn () => ComFourEntete::create(['numero' => 'CF-X', 'fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-16',
            'statut' => 'brouillon', 'devise' => 'XAF', 'created_by' => auth()->id()]));
        $this->assertSame($dhl, $autre->transporteur_id);

        // Modification du brouillon : changé, puis gardé quand il n'est pas renvoyé.
        $base = ['fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-16', 'lignes' => [['produit_id' => $this->produitId, 'quantite_commandee' => 5, 'prix_unitaire' => 80]]];
        $this->putJson("/api/commandes-fournisseur/{$parDefaut}", $base + ['transporteur_id' => $gozem])->assertOk();
        $this->putJson("/api/commandes-fournisseur/{$parDefaut}", $base)->assertOk();
        $this->assertSame($gozem, $this->chez(fn () => ComFourEntete::findOrFail($parDefaut)->transporteur_id));
        $filtrees = array_column($this->getJson("/api/commandes-fournisseur?transporteur_id={$gozem}")->assertOk()->json('data'), 'id');
        sort($filtrees);
        $this->assertSame([$parDefaut, $choisi], $filtrees);
    }

    public function test_filtres_controles_et_referentiel_protege(): void
    {
        $dhl = $this->transporteur('DHL');
        $gozem = $this->transporteur('Gozem');
        $libre = $this->transporteur('Sans clients');
        $clientDhl = $this->client('Quincaillerie Mballa', $dhl);
        $clientGozem = $this->client('Boutique du coin', $gozem);
        foreach ([$clientDhl, $clientGozem] as $client) {
            $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-16', 'lignes' => $this->lignesFacture()])->assertCreated();
            $this->postJson('/api/commandes-client', ['client_id' => $client, 'date_commande' => '2026-10-16',
                'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated();
            $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
                'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated();
        }

        // Listes filtrées par transporteur (avec son nom).
        $this->assertSame([[$clientGozem, 'Gozem']], array_map(fn ($f) => [$f['client_id'], $f['transporteur']['nom']],
            $this->getJson("/api/factures?transporteur_id={$gozem}")->assertOk()->json('data')));
        $this->assertSame([$clientDhl], array_column($this->getJson("/api/commandes-client?transporteur_id={$dhl}")->json('data'), 'client_id'));
        $this->assertSame([$clientGozem], array_column($this->getJson("/api/devis?transporteur_id={$gozem}")->json('data'), 'client_id'));
        $this->assertSame([$clientDhl], array_column($this->getJson("/api/clients?transporteur_id={$dhl}")->json('data'), 'id'));

        // Un transporteur d'une autre entreprise, ou retiré du référentiel, est refusé.
        $etranger = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id, fn () => Transporteur::create(['nom' => 'Ailleurs'])->id);
        $this->postJson('/api/factures', ['client_id' => $clientDhl, 'date_facture' => '2026-10-16', 'transporteur_id' => $etranger, 'lignes' => $this->lignesFacture()])
            ->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);
        $this->postJson('/api/fournisseurs', ['code' => 'F-2', 'raison_sociale' => 'Ailleurs SA', 'transporteur_id' => $etranger])
            ->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);
        $this->deleteJson("/api/referentiels/transporteurs/{$libre}")->assertOk();
        $this->putJson("/api/clients/{$clientDhl}", ['transporteur_id' => $libre])->assertUnprocessable()->assertJsonValidationErrors(['transporteur_id']);

        // Rattaché à un client, un fournisseur ou un document, il ne se supprime pas (on le désactive).
        $this->deleteJson("/api/referentiels/transporteurs/{$dhl}")->assertUnprocessable()
            ->assertJsonPath('message', 'Ce transporteur est rattaché à des clients, des fournisseurs ou des documents : désactivez-le plutôt.');
        $fournisseurSeul = $this->transporteur('Fret fournisseur');
        $this->chez(fn () => Fournisseur::create(['code' => 'F-3', 'raison_sociale' => 'Cimencam', 'transporteur_id' => $fournisseurSeul]));
        $this->deleteJson("/api/referentiels/transporteurs/{$fournisseurSeul}")->assertUnprocessable();

        // Une autre entreprise ne voit pas ces filtres aboutir.
        Sanctum::actingAs(AdminUser::factory()->for($autre = Tenant::factory()->create(['modules' => ['ventes']]))->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame([], $this->getJson("/api/factures?transporteur_id={$gozem}")->assertOk()->json('data'));
    }
}
