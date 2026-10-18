<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\PortailCompte;
use App\Models\ProduitTenant;
use App\Models\Representant;
use App\Models\Tenant;
use App\Rapports\Production;
use App\Rapports\VentesParRepresentant;
use App\Services\DocumentsVentePdf;
use App\Services\Portail\CommandesPortail;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Représentants commerciaux (plan de parité fripv2, phase 1.11a) : le représentant du client passe sur ses devis,
 * commandes et factures (sauf choix contraire), suit les conversions ; la facture fige son taux de commission ; filtres
 * des listes, PDF, référentiel protégé, rapport des ventes par représentant ; isolation des entreprises.
 */
class RepresentantsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function representant(string $nom, float $taux): int
    {
        return $this->postJson('/api/referentiels/representants', ['nom' => $nom, 'taux_commission' => $taux, 'actif' => true])->assertCreated()->json('id');
    }

    private function client(string $nom, ?int $representant): int
    {
        return $this->postJson('/api/clients', [
            'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'representant_id' => $representant,
        ])->assertCreated()->json('id');
    }

    private function lignesFacture(int $quantite = 1, float $prix = 100): array
    {
        return [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => $prix, 'taux_tva' => 0]];
    }

    public function test_le_representant_du_client_suit_ses_documents_et_la_facture_fige_son_taux(): void
    {
        $awa = $this->representant('Awa Ndiaye', 5);
        $paul = $this->representant('Paul Mbarga', 8);
        $client = $this->client('Quincaillerie Mballa', $awa);
        $this->getJson("/api/clients/{$client}/fiche")->assertOk()->assertJsonPath('representant.nom', 'Awa Ndiaye');

        // Devis sans représentant demandé : celui du client ; choisi : un autre ; vidé exprès : aucun.
        $devis = $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('representant_id', $awa)->json('id');
        $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'representant_id' => $paul,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('representant_id', $paul);
        $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'representant_id' => null,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->assertJsonPath('representant_id', null);

        // Converti en commande puis en facture : le représentant suit ; la facture fige son taux (5 %).
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devis}/accepter")->assertOk();
        $commande = $this->postJson("/api/devis/{$devis}/convertir")->assertOk()->json('commande.id');
        $this->getJson("/api/commandes-client/{$commande}")->assertOk()->assertJsonPath('representant_id', $awa)->assertJsonPath('representant.nom', 'Awa Ndiaye');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk();
        $facture = $this->postJson("/api/factures/commande/{$commande}")->assertCreated()->json('id');
        $vue = $this->getJson("/api/factures/{$facture}")->assertOk()->json();
        $this->assertSame([$awa, 'Awa Ndiaye', 5.0], [$vue['representant_id'], $vue['representant']['nom'], (float) $vue['taux_commission']]);

        // Le taux du représentant change : les factures faites gardent le leur, les nouvelles prennent le nouveau.
        $this->putJson("/api/referentiels/representants/{$awa}", ['nom' => 'Awa Ndiaye', 'taux_commission' => 6, 'actif' => true])->assertOk();
        $this->assertSame(5.0, (float) $this->getJson("/api/factures/{$facture}")->json('taux_commission'));
        $saisie = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-17', 'lignes' => $this->lignesFacture()])->assertCreated()->json();
        $this->assertSame([$awa, 6.0], [$saisie['representant_id'], (float) $saisie['taux_commission']]);

        // Un devis facturé directement et une copie de devis gardent le représentant du devis.
        $devisPaul = $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16', 'representant_id' => $paul,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->json('id');
        $this->assertSame($paul, $this->postJson("/api/devis/{$devisPaul}/copier")->assertCreated()->json('devis.representant_id'));
        $this->postJson("/api/devis/{$devisPaul}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devisPaul}/accepter")->assertOk();
        $factureDevis = $this->postJson("/api/devis/{$devisPaul}/facturer")->assertCreated()->json('facture.id');
        $this->assertSame([$paul, 8.0], [$this->getJson("/api/factures/{$factureDevis}")->json('representant_id'), (float) $this->getJson("/api/factures/{$factureDevis}")->json('taux_commission')]);

        // Modification d'un brouillon : le représentant se change ou se retire ; non envoyé, il reste.
        $brouillon = $this->postJson('/api/commandes-client', ['client_id' => $client, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->putJson("/api/commandes-client/{$brouillon}", ['notes' => 'À livrer'])->assertOk();
        $this->getJson("/api/commandes-client/{$brouillon}")->assertJsonPath('representant_id', $awa);
        $this->putJson("/api/commandes-client/{$brouillon}", ['representant_id' => $paul])->assertOk();
        $this->getJson("/api/commandes-client/{$brouillon}")->assertJsonPath('representant_id', $paul);
        $this->putJson("/api/commandes-client/{$brouillon}", ['representant_id' => null])->assertOk();
        $this->getJson("/api/commandes-client/{$brouillon}")->assertJsonPath('representant_id', null);

        // Commande passée dans l'espace client : le représentant du client.
        $portail = $this->chez(fn () => app(CommandesPortail::class)->commander(
            PortailCompte::create(['client_id' => $client, 'nom' => 'Acheteur', 'email' => 'acheteur@client.test', 'actif' => true]),
            Client::findOrFail($client), ['lignes' => [['produit_id' => $this->produitId, 'quantite' => 1]]],
        ));
        $this->assertSame($awa, $portail->representant_id);

        // PDF : « Représentant » parmi les informations.
        $infos = $this->chez(fn () => app(DocumentsVentePdf::class)->devis(Devis::findOrFail($devis))['infos']);
        $this->assertSame('Awa Ndiaye', $infos['Représentant']);
    }

    public function test_filtres_controles_et_referentiel_protege(): void
    {
        $awa = $this->representant('Awa Ndiaye', 5);
        $paul = $this->representant('Paul Mbarga', 8);
        $libre = $this->representant('Sans clients', 3);
        $clientAwa = $this->client('Quincaillerie Mballa', $awa);
        $clientPaul = $this->client('Boutique du coin', $paul);
        foreach ([$clientAwa, $clientPaul] as $client) {
            $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-16', 'lignes' => $this->lignesFacture()])->assertCreated();
            $this->postJson('/api/commandes-client', ['client_id' => $client, 'date_commande' => '2026-10-16',
                'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated();
            $this->postJson('/api/devis', ['client_id' => $client, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
                'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated();
        }

        // Listes filtrées par représentant (avec son nom).
        $this->assertSame([[$clientPaul, 'Paul Mbarga']], array_map(fn ($f) => [$f['client_id'], $f['representant']['nom']],
            $this->getJson("/api/factures?representant_id={$paul}")->assertOk()->json('data')));
        $this->assertSame([$clientAwa], array_column($this->getJson("/api/commandes-client?representant_id={$awa}")->json('data'), 'client_id'));
        $this->assertSame([$clientPaul], array_column($this->getJson("/api/devis?representant_id={$paul}")->json('data'), 'client_id'));
        $this->assertSame([$clientAwa], array_column($this->getJson("/api/clients?representant_id={$awa}")->json('data'), 'id'));

        // Un représentant d'une autre entreprise, ou retiré du référentiel, est refusé.
        $etranger = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id, fn () => Representant::create(['nom' => 'Ailleurs'])->id);
        $this->postJson('/api/factures', ['client_id' => $clientAwa, 'date_facture' => '2026-10-16', 'representant_id' => $etranger, 'lignes' => $this->lignesFacture()])
            ->assertUnprocessable()->assertJsonValidationErrors(['representant_id']);
        $this->deleteJson("/api/referentiels/representants/{$libre}")->assertOk();
        $this->putJson("/api/clients/{$clientAwa}", ['representant_id' => $libre])->assertUnprocessable()->assertJsonValidationErrors(['representant_id']);

        // Rattaché à un client ou à un document, il ne se supprime pas (on le désactive).
        $this->deleteJson("/api/referentiels/representants/{$awa}")->assertUnprocessable()
            ->assertJsonPath('message', 'Ce représentant est rattaché à des clients ou à des documents de vente : désactivez-le plutôt.');

        // Une autre entreprise ne voit pas ces filtres aboutir.
        Sanctum::actingAs(AdminUser::factory()->for($autre = Tenant::factory()->create(['modules' => ['ventes']]))->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame([], $this->getJson("/api/factures?representant_id={$paul}")->assertOk()->json('data'));
    }

    public function test_rapport_des_ventes_par_representant(): void
    {
        $awa = $this->representant('Awa Ndiaye', 5);
        $paul = $this->representant('Paul Mbarga', 10);
        $clientAwa = $this->client('Quincaillerie Mballa', $awa);
        $clientPaul = $this->client('Boutique du coin', $paul);
        $sansRepresentant = $this->client('Client direct', null);
        $emettre = function (int $client, int $quantite, string $date = '2026-10-10') {
            $facture = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => $date, 'lignes' => $this->lignesFacture($quantite)])->assertCreated()->json();
            $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

            return $facture;
        };
        $grande = $emettre($clientAwa, 10);
        $emettre($clientAwa, 2);
        $emettre($clientPaul, 5);
        $emettre($sansRepresentant, 3);
        $emettre($clientPaul, 50, '2026-09-10');
        // Brouillon : ne compte pas.
        $this->postJson('/api/factures', ['client_id' => $clientPaul, 'date_facture' => '2026-10-10', 'lignes' => $this->lignesFacture(7)])->assertCreated();
        // Note de crédit émise sur une facture d'Awa (2 unités) : moins de ventes, moins de commission.
        $avoir = $this->postJson('/api/avoirs', ['facture_id' => $grande['id'], 'date_avoir' => '2026-10-12',
            'lignes' => [['facture_ligne_id' => $grande['lignes'][0]['id'], 'quantite' => 2]]])->assertCreated()->json('id');
        $this->postJson("/api/avoirs/{$avoir}/emettre")->assertOk();

        $rapport = $this->getJson('/api/rapports/bibliotheque/ventes-par-representant?du=2026-10-01&au=2026-10-31')->assertOk()->json();
        $this->assertEquals([
            ['Awa Ndiaye', 2, 1200, 200, 1000, 50],
            ['Paul Mbarga', 1, 500, 0, 500, 50],
            ['(Sans représentant)', 1, 300, 0, 300, 0],
        ], array_map(fn ($l) => [$l['representant'], $l['factures'], $l['ventes_ht'], $l['credits_ht'], $l['ventes_nettes'], $l['commission']], $rapport['lignes']));
        $this->assertEquals(100, $rapport['totaux']['commission']);

        // Filtré sur un représentant (son nom dans les paramètres lisibles) ; en anglais.
        $paulSeul = $this->getJson("/api/rapports/bibliotheque/ventes-par-representant?du=2026-09-01&au=2026-10-31&representant={$paul}&langue=en")->assertOk()->json();
        $this->assertEquals([['Paul Mbarga', 2, 5500, 550]], array_map(fn ($l) => [$l['representant'], $l['factures'], $l['ventes_nettes'], $l['commission']], $paulSeul['lignes']));
        $this->assertEquals($paul, $paulSeul['valeurs']['representant']);
        $rapport = app(VentesParRepresentant::class);
        $rapport->langue = 'en';
        $this->assertSame('Paul Mbarga', $this->chez(fn () => app(Production::class)->parametresLisibles($rapport, ['representant' => $paul]))['Sales rep']);
    }
}
