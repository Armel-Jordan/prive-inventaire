<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\Tournee;
use App\Services\MatieresDangereusesPdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matières dangereuses (phase 1.6) : numéro ONU, classe, groupe d'emballage, poids et volume des produits ; frais
 * appliqué d'office aux matières dangereuses ; charge et matières dangereuses d'une tournée ; document de transport
 * (lignes encore à livrer, masse brute, téléphone d'urgence, déclaration de l'entreprise).
 */
class MatieresDangereusesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $secteurId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->secteurId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Station Bonamoussadi', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            Secteur::create(['code' => 'A1', 'nom' => 'Allée 1'])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function produit(string $numero, array $transport = []): int
    {
        return $this->postJson('/api/produits', ['numero' => $numero, 'description' => "Produit {$numero}", 'secteur_id' => $this->secteurId, 'prix_unitaire' => 100] + $transport)
            ->assertCreated()->json('produit.id');
    }

    /** Bon de livraison prêt d'une facture émise. */
    private function bonPret(array $lignes): array
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => array_map(fn ($l) => ['produit_id' => $l[0], 'quantite' => $l[1], 'prix_unitaire_ht' => 100], $lignes)])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();

        return $bon;
    }

    public function test_fiche_produit_et_frais_des_matieres_dangereuses(): void
    {
        // Numéro ONU mis en forme, classe, groupe, poids et volume ; valeurs invalides refusées.
        $essence = $this->produit('ESS', ['numero_onu' => '1203', 'classe_danger' => '3', 'groupe_emballage' => 'II', 'poids_kg' => 0.75, 'volume_m3' => 0.001]);
        $fiche = $this->getJson("/api/produits/{$essence}")->assertOk()->json();
        $this->assertSame(['UN1203', '3', 'II'], [$fiche['numero_onu'], $fiche['classe_danger'], $fiche['groupe_emballage']]);
        $this->assertEquals([0.75, 0.001], [(float) $fiche['poids_kg'], (float) $fiche['volume_m3']]);
        $this->postJson('/api/produits', ['numero' => 'X1', 'description' => 'X', 'secteur_id' => $this->secteurId, 'numero_onu' => 'ABC', 'groupe_emballage' => 'IV'])
            ->assertUnprocessable()->assertJsonValidationErrors(['numero_onu', 'groupe_emballage']);
        $this->putJson("/api/produits/{$essence}", ['classe_danger' => '2.1'])->assertOk();
        $this->assertSame('2.1', $this->getJson("/api/produits/{$essence}")->json('classe_danger'));
        $ciment = $this->produit('CIM', ['poids_kg' => 50]);

        // Frais « matières dangereuses » appliqué d'office, sans liste de produits.
        $produitFrais = $this->produit('FRAIS-MD');
        $this->postJson('/api/frais', ['libelle' => 'Manutention matières dangereuses', 'produit_id' => $produitFrais, 'mode' => 'montant', 'valeur' => 25,
            'application' => 'produits'])->assertUnprocessable()->assertJsonValidationErrors(['produits']);
        $this->postJson('/api/frais', ['libelle' => 'Manutention matières dangereuses', 'produit_id' => $produitFrais, 'mode' => 'montant', 'valeur' => 25,
            'application' => 'produits', 'matieres_dangereuses' => true])->assertCreated()->assertJsonPath('matieres_dangereuses', true);
        $calcul = fn (int $produit) => $this->postJson('/api/frais/calculer', ['document' => 'facture', 'client_id' => $this->clientId,
            'lignes' => [['produit_id' => $produit, 'quantite' => 2, 'prix_unitaire_ht' => 100]]])->assertOk()->json();
        $this->assertSame(['Manutention matières dangereuses'], array_column($calcul($essence), 'libelle'));
        $this->assertSame([], $calcul($ciment));
    }

    public function test_charge_de_la_tournee_et_document_de_transport(): void
    {
        $essence = $this->produit('ESS', ['numero_onu' => 'UN 1203', 'classe_danger' => '3', 'groupe_emballage' => 'II', 'poids_kg' => 0.75]);
        $gaz = $this->produit('GPL', ['numero_onu' => '1075', 'classe_danger' => '2.1']);
        $ciment = $this->produit('CIM', ['poids_kg' => 50]);
        $this->putJson('/api/parametres', ['md_telephone_urgence' => '+237 699 00 00 00', 'md_declaration' => 'Déclaration de test.'])->assertOk();
        $camion = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-001-AA', 'statut' => 'disponible', 'capacite_kg' => 1000])->id);
        $tournee = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camion, 'zone' => 'Douala nord'])->assertCreated()->json('id');

        // Tournée sans matière dangereuse : pas de document.
        $this->getJson("/api/tournees/{$tournee}/matieres-dangereuses")->assertUnprocessable();

        $bon = $this->bonPret([[$essence, 10], [$gaz, 4], [$ciment, 2]]);
        $this->postJson("/api/tournees/{$tournee}/ajouter-bon", ['bon_livraison_id' => $bon['id']])->assertOk();

        // Charge estimée : 10 × 0,75 + 2 × 50 = 107,5 kg (le gaz n'a pas de poids) ; deux lignes dangereuses.
        $this->getJson("/api/tournees/{$tournee}")->assertOk()->assertJsonPath('poids_total_kg', 107.5)->assertJsonPath('matieres_dangereuses', 2);

        // Document : les deux lignes dangereuses, masse brute connue, masse totale incomplète ; urgence et déclaration.
        $lignes = $this->chezLeTenant(fn () => app(MatieresDangereusesPdf::class)->lignes(Tournee::findOrFail($tournee)));
        $this->assertSame([['UN1203', '3', 'II', 10, 7.5], ['UN1075', '2.1', null, 4, null]],
            $lignes->map(fn ($l) => [$l['numero_onu'], $l['classe'], $l['groupe'], $l['quantite'], $l['masse_kg']])->all());
        $html = $this->chezLeTenant(fn () => view('pdf.matieres-dangereuses', app(MatieresDangereusesPdf::class)->donnees(Tournee::findOrFail($tournee), $lignes))->render());
        foreach (['UN1203', 'UN1075', "En cas d'urgence : +237 699 00 00 00", 'Déclaration de test.', 'LT-001-AA', '7,50 kg', 'la masse totale est incomplète', '2.1, 3'] as $attendu) {
            $this->assertStringContainsString($attendu, html_entity_decode($html, ENT_QUOTES), $attendu);
        }
        $this->assertStringStartsWith('%PDF', $this->get("/api/tournees/{$tournee}/matieres-dangereuses")->assertOk()->getContent());

        // Une fois livrées, plus rien à déclarer.
        $this->postJson("/api/tournees/{$tournee}/demarrer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => array_map(fn ($l) => ['id' => $l['id'], 'quantite_livree' => $l['quantite_a_livrer']], $bon['lignes'])])->assertOk();
        $this->getJson("/api/tournees/{$tournee}/matieres-dangereuses")->assertUnprocessable();
    }
}
