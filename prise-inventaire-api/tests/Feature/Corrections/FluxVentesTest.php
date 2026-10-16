<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Corrections du flux de vente trouvées par la campagne de tests du 2026-10-16 : livraison complète
 * refacturée, numérotation absente, devis converti plusieurs fois ou expiré, taxes de la conversion,
 * produits d'une autre entreprise, bon annulé bloquant, suppression d'un client facturé, modification
 * d'une commande, numérotation des commandes fournisseur, échéances, sur-réception.
 * Aucune numérotation n'est créée à l'avance : chaque entité reçoit la sienne au premier besoin.
 */
class FluxVentesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function factureEmise(int $quantite): int
    {
        $id = $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    /** Bon de livraison de la facture, préparé puis prêt à partir. */
    private function bonPret(int $factureId): array
    {
        $bon = $this->postJson("/api/factures/{$factureId}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();

        return $bon;
    }

    private function devisAccepte(string $date = '2026-10-16', string $validite = '2026-11-16'): int
    {
        $id = $this->postJson('/api/devis', [
            'client_id' => $this->clientId, 'date_devis' => $date, 'date_validite' => $validite,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/devis/{$id}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$id}/accepter")->assertOk();

        return $id;
    }

    public function test_une_livraison_complete_ne_refacture_rien(): void
    {
        $bon = $this->bonPret($this->factureEmise(3));

        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [['id' => $bon['lignes'][0]['id'], 'quantite_livree' => 3]]])
            ->assertOk()->assertJsonPath('statut', 'livre_complet');

        $this->assertSame(1, $this->chezLeTenant(fn () => Facture::count()));
    }

    public function test_une_livraison_partielle_facture_seulement_le_reste(): void
    {
        $factureId = $this->factureEmise(3);
        $bon = $this->bonPret($factureId);

        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [['id' => $bon['lignes'][0]['id'], 'quantite_livree' => 1]]])
            ->assertOk()->assertJsonPath('statut', 'livre_partiel');

        $reste = $this->chezLeTenant(fn () => Facture::with('lignes')->where('facture_mere_id', $factureId)->sole());
        $this->assertSame('brouillon', $reste->statut);
        $this->assertSame(2, (int) $reste->lignes->sole()->quantite);
    }

    public function test_numerotations_creees_au_premier_besoin(): void
    {
        $entites = collect($this->getJson('/api/configurations')->assertOk()->json())->pluck('entite');

        foreach (['devis', 'commande', 'commande_fournisseur', 'facture', 'bon_livraison', 'tournee'] as $entite) {
            $this->assertContains($entite, $entites);
        }
    }

    public function test_un_bon_annule_n_empeche_pas_un_nouveau_bon(): void
    {
        $factureId = $this->factureEmise(2);
        $annule = $this->postJson("/api/factures/{$factureId}/creer-bl")->assertCreated()->json('id');
        $this->postJson("/api/bons-livraison/{$annule}/annuler")->assertOk();

        $nouveau = $this->postJson("/api/factures/{$factureId}/creer-bl")->assertCreated()->json('id');
        $this->postJson("/api/factures/{$factureId}/creer-bl")->assertUnprocessable();

        $this->getJson("/api/factures/{$factureId}")->assertOk()->assertJsonPath('bon_livraison.id', $nouveau);
        $this->assertContains($nouveau, collect($this->getJson('/api/factures')->json('data'))->pluck('bon_livraison.id'));
    }

    public function test_un_devis_se_convertit_une_seule_fois_avec_le_groupe_de_taxes_par_defaut(): void
    {
        // Groupe par défaut TVA 17,5 % + CAC 10 % de la TVA : 19,25 %, comme une commande saisie directement.
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->json('id');
        $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'par_defaut' => true, 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated();
        $devisId = $this->devisAccepte();

        $commande = $this->postJson("/api/devis/{$devisId}/convertir")->assertOk()->json('commande');
        $this->assertEquals(1000, $commande['montant_ht']);
        $this->assertEquals(1192.5, $commande['montant_ttc']);

        $this->postJson("/api/devis/{$devisId}/convertir")->assertUnprocessable()
            ->assertJsonPath('commande.numero', $commande['numero']);
        $this->getJson("/api/devis/{$devisId}")->assertOk()->assertJsonPath('commande.numero', $commande['numero']);
        $this->assertSame(1, $this->chezLeTenant(fn () => ComClientEntete::count()));
    }

    public function test_un_devis_expire_ne_s_accepte_plus(): void
    {
        $id = $this->postJson('/api/devis', [
            'client_id' => $this->clientId, 'date_devis' => '2026-08-01', 'date_validite' => '2026-08-31',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/devis/{$id}/envoyer")->assertOk();

        $this->postJson("/api/devis/{$id}/accepter")->assertUnprocessable();
        $this->getJson('/api/devis?statut=expire')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_produits_et_camions_d_une_autre_entreprise_refuses(): void
    {
        $autre = Tenant::factory()->create();
        [$produitAutre, $camionAutre] = app(TenantContext::class)->runAsTenant($autre->id, fn () => [
            ProduitTenant::create(['numero' => 'X-1', 'description' => 'Autre', 'prix_unitaire' => 1])->id,
            Camion::create(['immatriculation' => 'LT-001-AA', 'statut' => 'disponible'])->id,
        ]);

        foreach ([$produitAutre, 0] as $produit) {
            $ligne = ['produit_id' => $produit, 'quantite' => 1, 'prix_unitaire_ht' => 100];
            $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'lignes' => [$ligne]])
                ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');
            $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'lignes' => [$ligne]])
                ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');
        }
        $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camionAutre])
            ->assertUnprocessable()->assertJsonValidationErrors('camion_id');
    }

    public function test_un_taux_de_tva_local_est_accepte(): void
    {
        $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25]],
        ])->assertCreated()->assertJsonPath('montant_ttc', fn ($ttc) => abs($ttc - 1192.5) < 0.01);
    }

    public function test_un_client_qui_a_des_documents_ne_se_supprime_pas(): void
    {
        $this->factureEmise(1);

        $this->deleteJson("/api/clients/{$this->clientId}")->assertUnprocessable();
        $this->assertNotNull($this->chezLeTenant(fn () => Client::find($this->clientId)));
    }

    public function test_modifier_une_commande_change_client_date_et_notes(): void
    {
        $autreClient = $this->chezLeTenant(fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Autre client', 'adresse_facturation' => 'Rue', 'ville' => 'Yaoundé', 'code_postal' => '0'])->id);
        $id = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'notes' => 'À livrer le matin',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');

        $this->putJson("/api/commandes-client/{$id}", ['client_id' => $autreClient, 'date_commande' => '2026-10-10', 'notes' => ''])->assertOk();

        $commande = $this->chezLeTenant(fn () => ComClientEntete::find($id));
        $this->assertSame([$autreClient, '2026-10-10', null], [(int) $commande->client_id, $commande->date_commande->toDateString(), $commande->notes]);
    }

    public function test_les_commandes_fournisseur_ont_leur_propre_numerotation(): void
    {
        $fournisseur = $this->chezLeTenant(fn () => Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam', 'devise' => 'XAF'])->id);

        $commande = $this->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseur, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite_commandee' => 10, 'prix_unitaire' => 80]],
        ])->assertCreated()->assertJsonPath('devise', 'XAF')->json();
        $this->assertStringStartsWith('BC-', $commande['numero']);

        $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->assertJsonPath('numero', 'CMD-00001');

        // Sur-réception groupée : 422 (et non 500).
        $this->postJson("/api/commandes-fournisseur/{$commande['id']}/valider")->assertOk();
        $this->postJson('/api/receptions/multiple', [
            'commande_id' => $commande['id'], 'date_reception' => '2026-10-16',
            'receptions' => [['com_four_ligne_id' => $commande['lignes'][0]['id'], 'quantite_recue' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors('receptions');
    }

    public function test_les_echeances_suivent_les_paiements(): void
    {
        $commandeId = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$commandeId}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commandeId}/accepter")->assertOk();
        $factureId = $this->postJson("/api/factures/commande/{$commandeId}")->assertCreated()->json('id');
        $this->postJson("/api/factures/{$factureId}/emettre")->assertOk();
        $payer = fn (int $montant) => $this->postJson("/api/factures/{$factureId}/paiement", ['montant' => $montant, 'date_paiement' => '2026-10-16', 'mode_paiement' => 'especes'])->assertOk();

        $payer(400);
        $this->getJson("/api/factures/{$factureId}")->assertOk()->assertJsonPath('echeances.0.statut', 'partiellement_payee');
        $payer(600);
        $this->getJson("/api/factures/{$factureId}")->assertOk()
            ->assertJsonPath('statut', 'payee')->assertJsonPath('echeances.0.statut', 'payee');
    }
}
