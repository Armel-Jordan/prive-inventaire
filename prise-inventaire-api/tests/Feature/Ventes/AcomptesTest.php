<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Acomptes et demandes d'acompte (phase 1.5) : acompte réservé à une commande et appliqué à l'émission de sa
 * facture, acompte libre appliqué puis remboursé, comptes clients, demande d'acompte (brouillon, envoi par courriel,
 * paiement, application), droits et isolation.
 */
class AcomptesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir('admin');
    }

    private function agir(string $role, ?AdminUser $compte = null): AdminUser
    {
        $compte ??= AdminUser::factory()->for($this->tenant)->create(['role' => $role]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    /** Facture émise de $quantite × 100 HT à 20 % (TTC : 120 par unité). */
    private function factureEmise(int $quantite = 1): int
    {
        $id = $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    private function commandeAcceptee(int $quantite): int
    {
        $id = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$id}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$id}/accepter")->assertOk();

        return $id;
    }

    private function acompte(float $montant, array $autres = []): array
    {
        return $this->postJson('/api/acomptes', $autres + [
            'client_id' => $this->clientId, 'date_acompte' => '2026-10-16', 'mode' => 'cheque', 'reference' => 'CHQ-1', 'montant' => $montant,
        ])->assertCreated()->json();
    }

    public function test_acompte_reserve_a_une_commande_applique_a_l_emission_de_sa_facture(): void
    {
        $commande = $this->commandeAcceptee(5);
        $acompte = $this->acompte(200, ['commande_id' => $commande, 'date_acompte' => '2026-10-10']);
        $this->assertEquals([200, 'disponible'], [$acompte['disponible'], $acompte['etat']]);

        // La facture de la commande reste un brouillon : rien n'est appliqué avant son émission.
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $this->assertEquals(600, $this->getJson("/api/factures/{$facture}")->json('reste_a_payer'));

        $emise = $this->postJson("/api/factures/{$facture}/emettre")->assertOk()->assertJsonPath('statut', 'partiellement_payee')->json();
        $this->assertEquals([200, 400], [$emise['montant_paye'], $emise['reste_a_payer']]);
        $paiement = $this->getJson("/api/factures/{$facture}")->json('paiements.0');
        $this->assertSame([$acompte['id'], 'CHQ-1'], [$paiement['acompte_id'], $paiement['acompte']['reference']]);
        $apres = $this->getJson("/api/acomptes/{$acompte['id']}")->assertOk()->json();
        $this->assertEquals([200, 0, null, 'utilise'], [$apres['montant_applique'], $apres['disponible'], $apres['commande_id'], $apres['etat']]);
        $this->assertSame($facture, $apres['applications'][0]['facture']['id']);

        // Relevé : l'acompte au crédit, la facture au débit ; son application n'est pas un mouvement de plus.
        $releve = $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->assertOk()->json();
        $this->assertSame(['acompte', 'facture'], array_column($releve['lignes'], 'type'));
        $this->assertEquals(400, $releve['solde_final']);
        $this->assertEquals([['FAC-00001', 400]], array_map(fn ($f) => [$f['numero'], $f['reste']], $releve['factures_dues']));
    }

    public function test_acompte_libre_applique_puis_rembourse(): void
    {
        $facture = $this->factureEmise();
        $acompte = $this->acompte(200)['id'];

        // Analyse : la facture due (120) et l'acompte disponible (200), dû au client.
        $client = $this->getJson('/api/comptes-clients')->assertOk()->json('clients.0');
        $this->assertEquals([120, 200, -80], [$client['total'], $client['credits'], $client['solde']]);

        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 150, 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 120, 'date' => '2026-10-16'])
            ->assertOk()->assertJsonPath('etat', 'entame');
        $this->getJson("/api/factures/{$facture}")->assertJsonPath('statut', 'payee');
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 10, 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('facture_id');

        $this->postJson("/api/acomptes/{$acompte}/rembourser", ['montant' => 100, 'date_remboursement' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $rembourse = $this->postJson("/api/acomptes/{$acompte}/rembourser", ['montant' => 80, 'date_remboursement' => '2026-10-16', 'reference' => 'CHQ-R1'])
            ->assertOk()->assertJsonPath('etat', 'utilise')->json();
        // L'argent ressort par où il est entré : le mode de l'acompte.
        $this->assertSame('cheque', $rembourse['remboursements'][0]['mode']);
        $this->deleteJson("/api/acomptes/{$acompte}")->assertUnprocessable();
        $this->putJson("/api/acomptes/{$acompte}", ['montant' => 300])->assertUnprocessable();
        $this->putJson("/api/acomptes/{$acompte}", ['reference' => 'CHQ-1 bis'])->assertOk()->assertJsonPath('reference', 'CHQ-1 bis');

        $this->assertSame(0, $this->getJson('/api/acomptes?disponibilite=avec_solde')->assertOk()->json('total'));
        $liste = $this->getJson('/api/acomptes?disponibilite=utilise&recherche=CHQ')->assertOk()->json();
        $this->assertEquals([1, 200, 0], [$liste['total'], $liste['totaux']['montant'], $liste['totaux']['disponible']]);

        $releve = $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->assertOk()->json();
        $this->assertSame(['facture', 'acompte', 'remboursement'], array_column($releve['lignes'], 'type'));
        $this->assertEquals([200, 200, 0], [$releve['total_debit'], $releve['total_credit'], $releve['solde_final']]);
        $this->assertSame([], $releve['acomptes_disponibles']);
    }

    public function test_demande_d_acompte_envoyee_payee_puis_appliquee(): void
    {
        $commande = $this->commandeAcceptee(5);
        $demande = $this->postJson('/api/demandes-acompte', [
            'client_id' => $this->clientId, 'date_demande' => '2026-10-16', 'commande_id' => $commande,
            'description' => "Acompte de 30 %\navant la livraison", 'montant' => 150,
        ])->assertCreated()->assertJsonPath('numero', 'DA-00001')->assertJsonPath('statut', 'brouillon')->json('id');
        $this->putJson("/api/demandes-acompte/{$demande}", ['montant' => 180])->assertOk()->assertJsonPath('montant', '180.00');

        $pdf = $this->get("/api/demandes-acompte/{$demande}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Envoyée par courriel : elle passe « envoyée » et ne se modifie plus.
        Mail::fake();
        $courriel = $this->getJson("/api/courriels/preparer?type=demande_acompte&document_id={$demande}")->assertOk()->json();
        $this->assertSame(["Demande d'acompte DA-00001 — {$this->tenant->nom}", ['achats@client.test'], 'demande-acompte-DA-00001.pdf'],
            [$courriel['objet'], $courriel['destinataires'], $courriel['piece_jointe']]);
        $this->postJson('/api/courriels/envois', [
            'type' => 'demande_acompte', 'document_id' => $demande, 'langue' => 'fr', 'destinataires' => ['achats@client.test'],
            'objet' => $courriel['objet'], 'contenu' => $courriel['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye');
        $this->getJson("/api/demandes-acompte/{$demande}")->assertJsonPath('statut', 'envoyee');
        $this->putJson("/api/demandes-acompte/{$demande}", ['montant' => 200])->assertUnprocessable();

        // Payée : l'acompte est créé, réservé à la commande de la demande.
        $payee = $this->postJson("/api/demandes-acompte/{$demande}/encaisser", ['date' => '2026-10-16', 'mode' => 'virement', 'reference' => 'VIR-9'])
            ->assertOk()->assertJsonPath('statut', 'payee')->json();
        $this->assertEquals([180, $commande, $demande, 'virement'], [$payee['acompte']['montant'], $payee['acompte']['commande_id'], $payee['acompte']['demande_id'], $payee['acompte']['mode']]);
        $this->postJson("/api/demandes-acompte/{$demande}/encaisser", ['date' => '2026-10-16', 'mode' => 'virement'])->assertUnprocessable();
        $this->deleteJson("/api/demandes-acompte/{$demande}")->assertUnprocessable();

        // Facture de la commande (600) émise : l'acompte s'applique ; la demande est « appliquée ».
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk()->assertJsonPath('reste_a_payer', '420.00');
        $this->getJson("/api/demandes-acompte/{$demande}")->assertJsonPath('etat', 'appliquee');
        $this->assertSame(['appliquee'], array_column($this->getJson('/api/demandes-acompte?recherche=livraison')->json('data'), 'etat'));

        // Un acompte d'une demande, saisi à tort et inutilisé, se supprime : la demande redevient envoyée.
        $autre = $this->postJson('/api/demandes-acompte', ['client_id' => $this->clientId, 'date_demande' => '2026-10-16', 'description' => 'Dépôt', 'montant' => 50])->json('id');
        $this->postJson("/api/demandes-acompte/{$autre}/envoyer")->assertOk()->assertJsonPath('statut', 'envoyee');
        $acompte = $this->postJson("/api/demandes-acompte/{$autre}/encaisser", ['date' => '2026-10-16', 'mode' => 'especes'])->json('acompte_id');
        $this->deleteJson("/api/acomptes/{$acompte}")->assertOk();
        $this->getJson("/api/demandes-acompte/{$autre}")->assertJsonPath('statut', 'envoyee')->assertJsonPath('acompte_id', null);

        $this->assertSame('DA-00001', $this->getJson('/api/recherche?q=DA-00001&types=demandes_acompte')->assertOk()->json('groupes.0.resultats.0.titre'));
    }

    public function test_controles_droits_et_isolation(): void
    {
        $facture = $this->factureEmise();
        $acompte = $this->acompte(100)['id'];
        // Commande d'un autre client, montant nul, date d'application avant l'acompte.
        $autreClient = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Autre', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $this->postJson('/api/acomptes', ['client_id' => $autreClient, 'date_acompte' => '2026-10-16', 'mode' => 'especes', 'montant' => 10, 'commande_id' => $this->commandeAcceptee(1)])
            ->assertUnprocessable()->assertJsonValidationErrors('commande_id');
        $this->postJson('/api/acomptes', ['client_id' => $this->clientId, 'date_acompte' => '2026-10-16', 'mode' => 'especes', 'montant' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 10, 'date' => '2026-10-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        // Rôles système : les droits des factures (« user » consulte seulement).
        $this->agir('user');
        $this->getJson('/api/acomptes')->assertOk();
        $this->postJson('/api/acomptes', ['client_id' => $this->clientId, 'date_acompte' => '2026-10-16', 'mode' => 'especes', 'montant' => 10])->assertForbidden();
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 10, 'date' => '2026-10-16'])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Appliquer un acompte sur une facture » (Acomptes).');
        $this->agir('manager');
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $facture, 'montant' => 10, 'date' => '2026-10-16'])->assertOk();

        // Catalogue : les deux écrans et leurs actions nommées.
        $this->agir('admin');
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['acomptes.appliquer', 'acomptes.rembourser'], array_slice(array_column($modules['acomptes']['permissions'], 'code'), 4));
        $this->assertSame(['demandes_acompte.envoyer', 'demandes_acompte.encaisser'], array_slice(array_column($modules['demandes_acompte']['permissions'], 'code'), 4));

        // Une autre entreprise ne voit ni n'utilise ces acomptes.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame(0, $this->getJson('/api/acomptes')->assertOk()->json('total'));
        $this->getJson("/api/acomptes/{$acompte}")->assertNotFound();
        $this->postJson("/api/acomptes/{$acompte}/rembourser", ['montant' => 1, 'date_remboursement' => '2026-10-16'])->assertNotFound();
    }
}
