<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\Devise;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ce que les écrans de vente et d'achat attendent de l'API : désignation des produits sur les lignes de
 * facture et de bon, bons encore sans tournée, détail complet d'une tournée après un ajout ou un retrait,
 * et un taux de change jamais inventé (référentiel de l'entreprise, sinon cours du jour, sinon erreur).
 */
class EcransVentesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        // Cache propre à chaque test : les taux (et les échecs) du jour sont mis en cache, et un cache sur
        // fichier (CI) les transmettait d'un test à l'autre.
        config(['cache.default' => 'array']);

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

    private function factureEmise(): int
    {
        $id = $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    private function bonPret(): array
    {
        $bon = $this->postJson('/api/factures/'.$this->factureEmise().'/creer-bl')->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();

        return $bon;
    }

    public function test_les_lignes_de_facture_et_de_bon_portent_le_produit_meme_retire_du_catalogue(): void
    {
        $factureId = $this->factureEmise();
        $bon = $this->postJson("/api/factures/{$factureId}/creer-bl")->assertCreated()->json();
        $this->chezLeTenant(fn () => ProduitTenant::find($this->produitId)->delete());

        $this->getJson("/api/factures/{$factureId}")->assertOk()
            ->assertJsonPath('lignes.0.produit.description', 'Ciment')
            ->assertJsonPath('lignes.0.produit.numero', 'PRD-1');
        $this->getJson("/api/bons-livraison/{$bon['id']}")->assertOk()
            ->assertJsonPath('lignes.0.produit.description', 'Ciment');
    }

    public function test_la_liste_des_bons_a_ajouter_exclut_ceux_deja_en_tournee(): void
    {
        $camionId = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-001-AA', 'statut' => 'disponible'])->id);
        $place = $this->bonPret();
        $libre = $this->bonPret();
        $tourneeId = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camionId])->assertCreated()->json('id');
        $this->postJson("/api/tournees/{$tourneeId}/ajouter-bon", ['bon_livraison_id' => $place['id']])->assertOk();

        $ids = collect($this->getJson('/api/bons-livraison?statut=pret&sans_tournee=1')->assertOk()->json('data'))->pluck('id');

        $this->assertEquals([$libre['id']], $ids->all());
    }

    public function test_ajouter_ou_retirer_un_bon_renvoie_la_tournee_complete(): void
    {
        $camionId = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-001-AA', 'statut' => 'disponible'])->id);
        $bon = $this->bonPret();
        $tourneeId = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camionId])->assertCreated()->json('id');

        $this->postJson("/api/tournees/{$tourneeId}/ajouter-bon", ['bon_livraison_id' => $bon['id']])->assertOk()
            ->assertJsonPath('camion.immatriculation', 'LT-001-AA')
            ->assertJsonPath('tournee_bons.0.bon_livraison.numero', $bon['numero'])
            ->assertJsonPath('tournee_bons.0.bon_livraison.facture.client.raison_sociale', 'Client Test');

        $this->deleteJson("/api/tournees/{$tourneeId}/bon/{$bon['id']}")->assertOk()
            ->assertJsonPath('camion.immatriculation', 'LT-001-AA')
            ->assertJsonCount(0, 'tournee_bons');
    }

    private function deviseReference(string $code): void
    {
        $this->chezLeTenant(fn () => TenantParametres::create(['devise_code' => $code, 'devise_symbole' => $code]));
    }

    public function test_le_taux_saisi_par_l_entreprise_prime_sur_le_cours_du_jour(): void
    {
        Http::fake();
        $this->deviseReference('CAD');
        $this->chezLeTenant(fn () => Devise::create(['code' => 'USD', 'libelle' => 'Dollar US', 'taux' => 1.35]));

        $this->getJson('/api/taux-change?from=USD&to=CAD')->assertOk()
            ->assertJsonPath('source', 'entreprise')
            ->assertJsonPath('taux', 1.35);
        Http::assertNothingSent();
    }

    public function test_le_taux_croise_passe_par_la_devise_de_reference(): void
    {
        Http::fake();
        $this->deviseReference('CAD');
        $this->chezLeTenant(function () {
            Devise::create(['code' => 'USD', 'libelle' => 'Dollar US', 'taux' => 1.35]);
            Devise::create(['code' => 'EUR', 'libelle' => 'Euro', 'taux' => 1.5]);
        });

        $taux = $this->getJson('/api/taux-change?from=USD&to=EUR')->assertOk()->json('taux');

        $this->assertEqualsWithDelta(0.9, $taux, 0.000001);
    }

    public function test_le_cours_du_jour_sert_quand_l_entreprise_n_a_pas_de_taux(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response(['date' => '2026-10-15', 'rates' => ['CAD' => 1.37]])]);
        $this->deviseReference('CAD');

        $this->getJson('/api/taux-change?from=USD&to=CAD')->assertOk()
            ->assertJsonPath('source', 'frankfurter')
            ->assertJsonPath('taux', 1.37)
            ->assertJsonPath('date', '2026-10-15');
    }

    public function test_un_taux_introuvable_n_est_jamais_remplace_par_1(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response(['message' => 'not found'], 404)]);
        $this->getJson('/api/taux-change?from=XAF&to=CAD')->assertNotFound();

        Http::fake(['api.frankfurter.app/*' => Http::response(['date' => '2026-10-15', 'rates' => []])]);
        $this->getJson('/api/taux-change?from=XOF&to=CAD')->assertNotFound();

        Http::fake(fn () => throw new ConnectionException('délai dépassé'));
        $this->getJson('/api/taux-change?from=GBP&to=CAD')->assertNotFound();

        $this->getJson('/api/taux-change?from=dollar&to=CAD')->assertUnprocessable();
    }

    public function test_un_echec_du_cours_du_jour_n_est_pas_redemande_a_chaque_ouverture(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::response(['message' => 'not found'], 404)]);

        $this->getJson('/api/taux-change?from=XAF&to=CAD')->assertNotFound();
        $this->getJson('/api/taux-change?from=XAF&to=CAD')->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_la_liste_des_fournisseurs_actifs_donne_leur_devise(): void
    {
        $this->chezLeTenant(fn () => Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Fournisseur US', 'devise' => 'USD']));

        $this->getJson('/api/fournisseurs/actifs')->assertOk()->assertJsonPath('0.devise', 'USD');
    }

    public function test_une_commande_fournisseur_refuse_un_taux_nul(): void
    {
        $fournisseurId = $this->chezLeTenant(fn () => Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Fournisseur US', 'devise' => 'USD'])->id);

        $this->postJson('/api/commandes-fournisseur', [
            'fournisseur_id' => $fournisseurId, 'date_commande' => '2026-10-16', 'devise' => 'USD', 'taux_change' => 0,
            'lignes' => [['produit_id' => $this->produitId, 'quantite_commandee' => 1, 'prix_unitaire' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('taux_change');
    }
}
