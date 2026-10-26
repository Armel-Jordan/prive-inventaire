<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\ClientContact;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Livrer à » et contact des documents de vente (plan de parité fripv2, socle S4.1) : par défaut le contact principal
 * et l'adresse de livraison principale du client ; l'adresse est recopiée sur le document (une adresse modifiée ensuite
 * ne change pas les documents faits) ; tout suit devis → commande → facture → bon de livraison ; le contact et
 * l'adresse doivent être ceux du client ; impression.
 */
class LivraisonContactDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = $this->chez(function () {
            $client = Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0']);
            $autre = Client::create(['code' => 'C2', 'raison_sociale' => 'Boutique du coin', 'adresse_facturation' => '2 rue', 'ville' => 'Yaoundé', 'code_postal' => '0']);
            $contact = fn (int $client, string $nom, bool $principal, bool $actif = true) => ClientContact::create(['client_id' => $client, 'nom' => $nom, 'telephone' => '699 00 00 00', 'principal' => $principal, 'actif' => $actif])->id;
            $adresse = fn (int $client, string $libelle, bool $principale, bool $actif = true) => ClientAdresse::create(['client_id' => $client, 'libelle' => $libelle, 'adresse' => "12 avenue {$libelle}",
                'ville' => 'Douala', 'code_postal' => 'BP 1', 'pays' => 'Cameroun', 'livraison' => true, 'principale' => $principale, 'instructions' => "Sonner au portail {$libelle}", 'actif' => $actif])->id;
            $this->produitId = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id;

            return [
                'client' => $client->id, 'autre' => $autre->id,
                'awa' => $contact($client->id, 'Awa', true), 'paul' => $contact($client->id, 'Paul', false), 'ancien' => $contact($client->id, 'Ancien', false, false),
                'etranger' => $contact($autre->id, 'Étranger', true),
                'chantier' => $adresse($client->id, 'Chantier', true), 'depot' => $adresse($client->id, 'Dépôt', false),
                'ailleurs' => $adresse($autre->id, 'Ailleurs', true),
            ];
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function devis(array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/devis', $autres + ['client_id' => $this->ids['client'], 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]]);
    }

    public function test_par_defaut_recopie_et_suivie_jusqu_au_bon_de_livraison(): void
    {
        // Par défaut : le contact principal et l'adresse de livraison principale, recopiée avec ses instructions.
        $devis = $this->devis()->assertCreated()->assertJsonPath('contact_id', $this->ids['awa'])->assertJsonPath('adresse_livraison_id', $this->ids['chantier'])
            ->assertJsonPath('livraison_adresse', "Chantier\n12 avenue Chantier\nBP 1 Douala\nCameroun")
            ->assertJsonPath('instructions_livraison', 'Sonner au portail Chantier')->json('id');
        $this->getJson("/api/devis/{$devis}")->assertOk()->assertJsonPath('contact.nom', 'Awa');

        // L'adresse modifiée ensuite ne change pas le devis.
        $this->chez(fn () => ClientAdresse::whereKey($this->ids['chantier'])->update(['adresse' => '99 nouvelle rue']));
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('livraison_adresse', "Chantier\n12 avenue Chantier\nBP 1 Douala\nCameroun");

        // Converti, facturé, livré : tout suit.
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('contact_id', $this->ids['awa'])->assertJsonPath('livraison_adresse', "Chantier\n12 avenue Chantier\nBP 1 Douala\nCameroun");
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->assertJsonPath('adresse_livraison_id', $this->ids['chantier'])->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->assertJsonPath('contact_id', $this->ids['awa'])
            ->assertJsonPath('instructions_livraison', 'Sonner au portail Chantier')->json('id');

        // Impression : contact et « Livrer à » sur la facture, adresse en destinataire du bon.
        $this->chez(function () use ($facture, $bon) {
            $infos = app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture))['infos'];
            $this->assertSame(['Awa — 699 00 00 00', 'Chantier, 12 avenue Chantier, BP 1 Douala, Cameroun', 'Sonner au portail Chantier'],
                [$infos['Contact'], $infos['Livrer à'], $infos['Instructions de livraison']]);
            $this->assertSame(['Chantier', '12 avenue Chantier', 'BP 1 Douala', 'Cameroun'], app(DocumentsVentePdf::class)->bonLivraison(BonLivraison::findOrFail($bon))['destinataire']['lignes']);
        });
    }

    public function test_choix_modification_et_controles(): void
    {
        // Choisis : un autre contact et une autre adresse ; vidés exprès : aucun.
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-10-16', 'contact_id' => $this->ids['paul'],
            'adresse_livraison_id' => $this->ids['depot'], 'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])
            ->assertCreated()->assertJsonPath('contact_id', $this->ids['paul'])->assertJsonPath('instructions_livraison', 'Sonner au portail Dépôt')->json('id');
        $this->devis(['contact_id' => null, 'adresse_livraison_id' => null])->assertCreated()->assertJsonPath('contact_id', null)->assertJsonPath('livraison_adresse', null);

        // Modification : la même adresse garde sa copie ; une autre est recopiée ; non transmis, rien ne change.
        $this->chez(fn () => ClientAdresse::whereKey($this->ids['depot'])->update(['adresse' => '1 rue changée']));
        $this->putJson("/api/commandes-client/{$commande}", ['adresse_livraison_id' => $this->ids['depot']])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('livraison_adresse', "Dépôt\n12 avenue Dépôt\nBP 1 Douala\nCameroun");
        $this->putJson("/api/commandes-client/{$commande}", ['adresse_livraison_id' => $this->ids['chantier']])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('adresse_livraison_id', $this->ids['chantier'])->assertJsonPath('instructions_livraison', 'Sonner au portail Chantier');
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Fragile'])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('contact_id', $this->ids['paul']);

        // Le contact et l'adresse d'un autre client, ou un contact désactivé : refusés.
        $this->devis(['contact_id' => $this->ids['etranger']])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
        $this->devis(['adresse_livraison_id' => $this->ids['ailleurs']])->assertUnprocessable()->assertJsonValidationErrors(['adresse_livraison_id']);
        $this->devis(['contact_id' => $this->ids['ancien']])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        // Facture saisie : par défaut, les principaux.
        $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]]])
            ->assertCreated()->assertJsonPath('contact_id', $this->ids['awa'])->assertJsonPath('adresse_livraison_id', $this->ids['chantier']);
    }
}
