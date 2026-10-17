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
 * Colis des bons de livraison (phase 1.6) : numérotation d'après le bon, quantités sans dépasser le restant, colis
 * modifié, vidé (supprimé) ou retiré, ligne d'un autre bon refusée, plus de modification une fois livré, droits et
 * isolation.
 */
class ColisLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private array $produits;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produits] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            [ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id, ProduitTenant::create(['numero' => 'SAB', 'description' => 'Sable', 'prix_unitaire' => 10])->id],
        ]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Bon de livraison en préparation : 10 sacs de ciment, 4 de sable. */
    private function bon(): array
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'lignes' => [
            ['produit_id' => $this->produits[0], 'quantite' => 10, 'prix_unitaire_ht' => 100], ['produit_id' => $this->produits[1], 'quantite' => 4, 'prix_unitaire_ht' => 10],
        ]])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();

        return $bon;
    }

    public function test_colis_numerotes_quantites_et_modifications(): void
    {
        $bon = $this->bon();
        [$ciment, $sable] = [$bon['lignes'][0]['id'], $bon['lignes'][1]['id']];
        $restants = fn () => array_column($this->getJson("/api/bons-livraison/{$bon['id']}/colis")->assertOk()->json('lignes'), 'restant');
        $this->assertSame([10, 4], $restants());

        // Deux colis : numérotés d'après le bon, chacun sans dépasser le restant.
        $premier = $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 6]], 'poids_kg' => 150])
            ->assertCreated()->json('colis.0');
        $this->assertSame($bon['numero'].'-001', $premier['numero']);
        $second = $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 4], ['bon_livraison_ligne_id' => $sable, 'quantite' => 4]]])
            ->assertCreated()->json('colis.1');
        $this->assertSame([$bon['numero'].'-002', [0, 0]], [$second['numero'], $restants()]);
        $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Quantité trop grande : il reste 0 à mettre en colis sur cette ligne.']);
        $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Mettez au moins une quantité dans le colis.']);

        // Ligne d'un autre bon refusée ; liste des bons : nombre de colis.
        $autre = $this->bon();
        $this->postJson("/api/bons-livraison/{$autre['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 1]]])->assertUnprocessable();
        $this->assertSame(2, collect($this->getJson('/api/bons-livraison')->json('data'))->firstWhere('id', $bon['id'])['colis_count']);

        // Colis modifié (moins de ciment), colis vidé (supprimé), colis retiré.
        $this->putJson("/api/bons-livraison/{$bon['id']}/colis/{$premier['id']}", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 3]], 'notes' => 'Palette 1'])
            ->assertOk()->assertJsonPath('colis.0.notes', 'Palette 1');
        $this->assertSame([3, 0], $restants());
        $this->putJson("/api/bons-livraison/{$bon['id']}/colis/{$second['id']}", ['lignes' => [['bon_livraison_ligne_id' => $ciment, 'quantite' => 0], ['bon_livraison_ligne_id' => $sable, 'quantite' => 0]]])
            ->assertOk()->assertJsonCount(1, 'colis');
        $this->deleteJson("/api/bons-livraison/{$bon['id']}/colis/{$premier['id']}")->assertOk()->assertJsonCount(0, 'colis');
        $this->assertSame([10, 4], $restants());
        // Un nouveau colis reprend le rang suivant.
        $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $sable, 'quantite' => 4]]])
            ->assertCreated()->assertJsonPath('colis.0.numero', $bon['numero'].'-001');
    }

    public function test_bon_livre_droits_et_isolation(): void
    {
        $bon = $this->bon();
        $ligne = $bon['lignes'][0]['id'];

        // Préparer un colis demande le droit de préparer les bons ; la liste se consulte.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['bons_livraison.preparer' => false]])->assertOk();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ligne, 'quantite' => 1]]])->assertForbidden();
        $this->getJson("/api/bons-livraison/{$bon['id']}/colis")->assertOk()->assertJsonPath('modifiable', true);

        // Bon livré : les colis ne se modifient plus.
        $this->agir($this->admin);
        $colis = $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ligne, 'quantite' => 2]]])->assertCreated()->json('colis.0.id');
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => array_map(fn ($l) => ['id' => $l['id'], 'quantite_livree' => $l['quantite_a_livrer']], $bon['lignes'])])->assertOk();
        $this->getJson("/api/bons-livraison/{$bon['id']}/colis")->assertJsonPath('modifiable', false);
        $this->deleteJson("/api/bons-livraison/{$bon['id']}/colis/{$colis}")->assertUnprocessable();

        // Une autre entreprise ne voit ni ne touche ces colis.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson("/api/bons-livraison/{$bon['id']}/colis")->assertNotFound();
        $this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $ligne, 'quantite' => 1]]])->assertNotFound();
        $this->putJson("/api/bons-livraison/{$bon['id']}/colis/{$colis}", ['lignes' => [['bon_livraison_ligne_id' => $ligne, 'quantite' => 0]]])->assertNotFound();
        $this->deleteJson("/api/bons-livraison/{$bon['id']}/colis/{$colis}")->assertNotFound();
    }
}
