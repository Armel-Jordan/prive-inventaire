<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Client de passage (phase 1.1) : fiche créée à sa première utilisation, coordonnées notées sur le devis, la commande
 * et la facture, reprises d'un document à l'autre, sur le PDF et dans le courriel ; ignorées pour un client à fiche,
 * conservées par une modification partielle.
 */
class ClientDePassageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->produitId, $this->clientId] = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
        ]);
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
    }

    private function coordonnees(): array
    {
        return ['passage_nom' => 'Jean Dupont', 'passage_telephone' => '699 00 00 00', 'passage_courriel' => 'jean@exemple.test', 'passage_adresse' => 'Marché central, Douala'];
    }

    public function test_client_de_passage_du_devis_a_la_facture(): void
    {
        // Fiche créée à la première demande, la même ensuite.
        $passage = $this->getJson('/api/clients/de-passage')->assertOk()->assertJsonPath('de_passage', true)->assertJsonPath('raison_sociale', 'Client de passage')->json();
        $this->assertSame($passage['id'], $this->getJson('/api/clients/de-passage')->json('id'));
        $this->assertSame('PASSAGE', $passage['code']);

        // Devis du client de passage, avec ses coordonnées ; converti en commande puis en facture, elles suivent.
        $devis = $this->postJson('/api/devis', $this->coordonnees() + ['client_id' => $passage['id'], 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('passage_nom', 'Jean Dupont')->json('id');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertOk()->assertJsonPath('passage_nom', 'Jean Dupont')->assertJsonPath('passage_courriel', 'jean@exemple.test');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $this->getJson("/api/factures/{$facture}")->assertOk()->assertJsonPath('passage_nom', 'Jean Dupont')->assertJsonPath('passage_adresse', 'Marché central, Douala');

        // Le PDF et le courriel portent ses coordonnées.
        $this->assertStringStartsWith('%PDF', $this->get("/api/factures/{$facture}/pdf")->assertOk()->getContent());
        $destinataire = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture))['destinataire']);
        $this->assertSame(['nom' => 'Jean Dupont', 'lignes' => ['Marché central, Douala', 'Tél. : 699 00 00 00', 'jean@exemple.test']], $destinataire);
        $courriel = $this->getJson("/api/courriels/preparer?type=facture&document_id={$facture}")->assertOk()->json();
        $this->assertSame(['jean@exemple.test'], $courriel['destinataires']);
        $this->assertStringNotContainsString('Client de passage', $courriel['contenu'].$courriel['objet']);

        // La recherche globale et celle des devis le trouvent par son nom.
        $groupes = collect($this->getJson('/api/recherche?q=Dupont&types=devis,commandes_client,factures')->assertOk()->json('groupes'))->keyBy('type');
        foreach (['devis', 'commandes_client', 'factures'] as $type) {
            $this->assertSame('Jean Dupont', $groupes[$type]['resultats'][0]['sous_titre'], $type);
        }
        $this->assertSame([$devis], array_column($this->getJson('/api/devis?search=Dupont')->assertOk()->json('data'), 'id'));
    }

    public function test_saisie_directe_client_a_fiche_et_modification_partielle(): void
    {
        $passage = $this->getJson('/api/clients/de-passage')->json('id');

        // Facture saisie pour le client de passage : coordonnées gardées ; pour un client à fiche : ignorées.
        $facture = $this->postJson('/api/factures', $this->coordonnees() + ['client_id' => $passage, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json();
        $this->assertSame('Jean Dupont', $facture['passage_nom']);
        $this->postJson('/api/factures', $this->coordonnees() + ['client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->assertJsonPath('passage_nom', null);
        $this->postJson('/api/factures', ['client_id' => $passage, 'date_facture' => '2026-10-16', 'passage_courriel' => 'pas-un-courriel',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertUnprocessable()->assertJsonValidationErrors(['passage_courriel']);

        // Commande modifiée sans ses coordonnées, ou avec une seule : les autres restent ; passée à un client à fiche : effacées.
        $commande = $this->postJson('/api/commandes-client', $this->coordonnees() + ['client_id' => $passage, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'À livrer'])->assertOk();
        $this->putJson("/api/commandes-client/{$commande}", ['passage_telephone' => ' 677 11 22 33 '])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('passage_nom', 'Jean Dupont')->assertJsonPath('passage_telephone', '677 11 22 33')->assertJsonPath('notes', 'À livrer');
        $this->putJson("/api/commandes-client/{$commande}", ['client_id' => $this->clientId])->assertOk();
        $this->getJson("/api/commandes-client/{$commande}")->assertJsonPath('passage_nom', null);
    }
}
