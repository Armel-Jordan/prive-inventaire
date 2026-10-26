<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TourneeBon;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Corrections du 2026-10-26 :
 * - A6 : un document tiré d'un autre prend la date de l'usager (envoyée par l'écran), sinon le jour de l'entreprise
 *   (TACHES_FUSEAU), et non la date UTC du serveur, qui donnait le lendemain le soir à l'ouest de Greenwich ;
 * - A3 : l'arrêt de la tournée qui porte un bon de livraison passe « livré » (ou « échec » si rien n'est livré) quand
 *   la livraison est enregistrée.
 */
class DatesEtArretsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        // 26 octobre, 2 h UTC : encore le 25 octobre au soir à Toronto.
        $this->travelTo('2026-10-26 02:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        [$this->clientId, $this->produitId] = $this->chez(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function devisAccepte(): int
    {
        $id = $this->postJson('/api/devis', [
            'client_id' => $this->clientId, 'date_devis' => '2026-10-20', 'date_validite' => '2026-11-20',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/devis/{$id}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$id}/accepter")->assertOk();

        return $id;
    }

    public function test_la_commande_tiree_d_un_devis_prend_la_date_de_l_usager(): void
    {
        $id = $this->devisAccepte();
        $this->postJson("/api/devis/{$id}/convertir", ['date' => '2026-10-30'])->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $commande = $this->postJson("/api/devis/{$id}/convertir", ['date' => '2026-10-25'])->assertSuccessful()->json('commande.id');
        $this->assertSame('2026-10-25', $this->chez(fn () => ComClientEntete::findOrFail($commande)->date_commande->toDateString()));
    }

    public function test_sans_date_envoyee_le_jour_de_l_entreprise(): void
    {
        config(['taches.fuseau' => 'America/Toronto']);
        $id = $this->devisAccepte();

        $commande = $this->postJson("/api/devis/{$id}/convertir")->assertSuccessful()->json('commande.id');
        $this->assertSame('2026-10-25', $this->chez(fn () => ComClientEntete::findOrFail($commande)->date_commande->toDateString()));
    }

    public function test_l_arret_de_la_tournee_suit_la_livraison_de_son_bon(): void
    {
        $camion = $this->chez(fn () => Camion::create(['immatriculation' => 'LT-001', 'actif' => true]));
        $tournee = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-26', 'camion_id' => $camion->id])->assertCreated()->json('id');

        $bons = [];
        foreach ([2, 1] as $quantite) {
            $facture = $this->postJson('/api/factures', [
                'client_id' => $this->clientId, 'date_facture' => '2026-10-25',
                'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100]],
            ])->assertCreated()->json('id');
            $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
            $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
            $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
            $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();
            $this->postJson("/api/tournees/{$tournee}/ajouter-bon", ['bon_livraison_id' => $bon['id']])->assertSuccessful();
            $bons[] = $bon;
        }

        // Premier bon livré en partie : l'arrêt est livré ; second bon, rien de livré : l'arrêt est en échec.
        $this->postJson("/api/bons-livraison/{$bons[0]['id']}/livrer", ['lignes' => [['id' => $bons[0]['lignes'][0]['id'], 'quantite_livree' => 1]]])->assertOk();
        $this->postJson("/api/bons-livraison/{$bons[1]['id']}/livrer", ['lignes' => [['id' => $bons[1]['lignes'][0]['id'], 'quantite_livree' => 0]]])->assertOk();

        $arrets = $this->chez(fn () => TourneeBon::where('tournee_id', $tournee)->get()->keyBy('bon_livraison_id'));
        $this->assertSame('livre', $arrets[$bons[0]['id']]->statut);
        $this->assertNotNull($arrets[$bons[0]['id']]->heure_livraison);
        $this->assertSame('echec', $arrets[$bons[1]['id']]->statut);
    }
}
