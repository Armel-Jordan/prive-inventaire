<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conditions de paiement sur les clients et les factures, escompte pour paiement rapide (phase 1.5) : échéance
 * tirée de la condition (délai, fin de mois), condition du client puis par défaut, escompte « 2/10 Net 30 » au premier
 * paiement qui règle tout le reste, comptes clients, PDF.
 */
class EscompteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function condition(array $champs): int
    {
        return $this->postJson('/api/referentiels/conditions_paiement', $champs + ['fin_de_mois' => false, 'actif' => true])->assertCreated()->json('id');
    }

    /** Facture de 100 HT à 20 % (120 TTC), émise. */
    private function facture(string $date, array $autres = [], bool $emettre = true): array
    {
        $facture = $this->postJson('/api/factures', $autres + [
            'client_id' => $this->clientId, 'date_facture' => $date,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json();
        if ($emettre) {
            $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        }

        return $facture;
    }

    public function test_condition_du_client_donne_l_echeance_et_l_escompte(): void
    {
        $condition = $this->condition(['code' => '2-10-N30', 'libelle' => '2/10 Net 30', 'delai_jours' => 30, 'escompte_pourcentage' => 2, 'escompte_jours' => 10]);
        $this->putJson("/api/clients/{$this->clientId}", ['condition_paiement_id' => $condition])->assertOk()->assertJsonPath('condition_paiement_id', $condition);

        $facture = $this->facture('2026-10-01');
        $this->assertSame([$condition, '2026-10-31'], [$facture['condition_paiement_id'], substr($facture['date_echeance'], 0, 10)]);

        $propose = $this->getJson("/api/factures/{$facture['id']}/escompte?date=2026-10-08")->assertOk()->json();
        $this->assertEquals([true, '2/10 Net 30', 2, '2026-10-11', 2.4, 117.6], [$propose['applicable'], $propose['condition'], $propose['pourcentage'], $propose['date_limite'], $propose['escompte'], $propose['a_payer']]);
        $this->getJson("/api/factures/{$facture['id']}/escompte?date=2026-10-12")->assertOk()->assertJsonPath('applicable', false);

        $payee = $this->postJson("/api/factures/{$facture['id']}/paiement", ['montant' => 117.6, 'date_paiement' => '2026-10-08', 'mode_paiement' => 'virement', 'escompte' => true])
            ->assertOk()->json();
        $this->assertSame('payee', $payee['statut']);
        $vue = $this->getJson("/api/factures/{$facture['id']}")->assertOk()->assertJsonPath('condition_paiement.libelle', '2/10 Net 30')->json();
        $this->assertEquals([117.6, 2.4, 0, 2.4], [$vue['montant_paye'], $vue['montant_escompte'], $vue['reste_a_payer'], $vue['paiements'][0]['escompte']]);
        $this->getJson("/api/factures/{$facture['id']}/escompte")->assertOk()->assertJsonPath('applicable', false);

        // Relevé : le paiement et l'escompte au crédit soldent la facture.
        $releve = $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->assertOk()->json();
        $this->assertEquals([['facture', 120, 0], ['paiement', 0, 117.6], ['escompte', 0, 2.4]], array_map(fn ($l) => [$l['type'], $l['debit'], $l['credit']], $releve['lignes']));
        $this->assertEquals(0, $releve['solde_final']);
        $this->assertSame([], $releve['factures_dues']);
        $this->assertStringStartsWith('%PDF', $this->get("/api/factures/{$facture['id']}/pdf")->assertOk()->getContent());
    }

    public function test_escompte_refuse_hors_delai_partiel_ou_apres_un_paiement(): void
    {
        $condition = $this->condition(['code' => '2-10-N30', 'libelle' => '2/10 Net 30', 'delai_jours' => 30, 'escompte_pourcentage' => 2, 'escompte_jours' => 10]);
        $facture = $this->facture('2026-10-01', ['condition_paiement_id' => $condition])['id'];

        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 117.6, 'date_paiement' => '2026-10-12', 'mode_paiement' => 'virement', 'escompte' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Escompte impossible : le paiement doit être fait au plus tard le 11/10/2026.');
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 50, 'date_paiement' => '2026-10-05', 'mode_paiement' => 'virement', 'escompte' => true])
            ->assertUnprocessable()->assertJsonPath('message', "Avec l'escompte, le paiement règle tout le reste : 117,60.");

        // Après un premier paiement, plus d'escompte (comme dans fripv2).
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 50, 'date_paiement' => '2026-10-05', 'mode_paiement' => 'virement'])->assertOk();
        $this->getJson("/api/factures/{$facture}/escompte?date=2026-10-06")->assertOk()->assertJsonPath('applicable', false);
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 68.6, 'date_paiement' => '2026-10-06', 'mode_paiement' => 'virement', 'escompte' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Aucun escompte possible sur cette facture.');

        // Sans condition : aucun escompte.
        $sansCondition = $this->facture('2026-10-01')['id'];
        $this->getJson("/api/factures/{$sansCondition}/escompte?date=2026-10-02")->assertOk()->assertJsonPath('applicable', false);
    }

    public function test_echeance_fin_de_mois_condition_par_defaut_et_facture_de_commande(): void
    {
        // Sans condition ni condition par défaut : pas d'échéance calculée, comme avant.
        $this->assertNull($this->facture('2026-10-16', [], false)['date_echeance']);

        $this->condition(['code' => 'N30FM', 'libelle' => '30 jours fin de mois', 'delai_jours' => 30, 'fin_de_mois' => true, 'par_defaut' => true]);
        $facture = $this->facture('2026-10-16', [], false);
        $this->assertSame('2026-11-30', substr($facture['date_echeance'], 0, 10));
        // Une échéance saisie est gardée.
        $this->assertSame('2026-12-15', substr($this->facture('2026-10-16', ['date_echeance' => '2026-12-15'], false)['date_echeance'], 0, 10));

        // Facture d'une commande : échéance de la condition (sans échéancier propre au client).
        $commande = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $deCommande = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json();
        $this->assertSame(['2026-11-30', '2026-11-30'], [substr($deCommande['date_echeance'], 0, 10), substr($deCommande['echeances'][0]['date_echeance'], 0, 10)]);

        // La condition d'une autre entreprise est refusée.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $etrangere = app(TenantContext::class)->runAsTenant($autre->id, fn () => \App\Models\ConditionPaiement::create(['code' => 'X', 'libelle' => 'X', 'delai_jours' => 1])->id);
        $this->putJson("/api/clients/{$this->clientId}", ['condition_paiement_id' => $etrangere])->assertUnprocessable()->assertJsonValidationErrors('condition_paiement_id');
    }
}
