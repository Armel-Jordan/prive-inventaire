<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Liens entre produits (phase 2.3) : composants d'un ensemble (sans boucle, imbriqués), annexes, équivalents dans les
 * deux sens ; disponibilités : un ensemble commandé réserve ses composants, son disponible vient d'eux ; équivalents
 * d'un produit en manque ; liste des produits avec annexes et composants ; droits et isolation.
 */
class LiensProduitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    /** @var array<string, int> numéro => id */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        $this->chez(function () {
            $this->clientId = Client::create(['code' => 'CLI', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            foreach (['PACK' => 'Pack apéro', 'COFFRET' => 'Coffret cadeau', 'BIERE' => 'Bière 33 cl', 'VERRE' => 'Verre', 'CHIPS' => 'Chips',
                'BIERE2' => 'Bière blonde 33 cl', 'APPAREIL' => 'Lampe', 'PILE' => 'Pile AA'] as $numero => $description) {
                $this->p[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => 100, 'mesure' => 'UN', 'actif' => true])->id;
            }
            // Stock compté : 30 bières, 4 verres, 10 chips, 8 bières blondes.
            foreach ([['BIERE', 30], ['VERRE', 4], ['CHIPS', 10], ['BIERE2', 8]] as [$numero, $quantite]) {
                ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => now()]);
            }
        });
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function lier(string $produit, string $type, string $lie, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/produits/{$this->p[$produit]}/liens", ['type' => $type, 'lie_id' => $this->p[$lie]] + $autres);
    }

    private function dispo(): array
    {
        return collect($this->getJson('/api/disponibilites?tous=1')->assertOk()->json('data'))->keyBy('numero')->all();
    }

    public function test_ensembles_annexes_et_equivalents(): void
    {
        // Le pack : 6 bières et 2 verres ; le coffret : un pack et 3 chips (ensemble dans l'ensemble).
        $this->lier('PACK', 'composant', 'BIERE', ['quantite' => 6])->assertCreated()->assertJsonPath('quantite', 6);
        $this->lier('PACK', 'composant', 'VERRE', ['quantite' => 2])->assertCreated();
        $this->lier('COFFRET', 'composant', 'PACK')->assertCreated();
        $this->lier('COFFRET', 'composant', 'CHIPS', ['quantite' => 3])->assertCreated();
        // Refus : le produit lui-même, une boucle, un doublon, un produit désactivé.
        $this->lier('PACK', 'composant', 'PACK')->assertUnprocessable()->assertJsonValidationErrors(['lie_id' => 'Un produit ne se lie pas à lui-même.']);
        $this->lier('BIERE', 'composant', 'COFFRET')->assertUnprocessable()
            ->assertJsonValidationErrors(['lie_id' => "COFFRET contient déjà BIERE : l'ensemble tournerait en rond."]);
        $this->lier('PACK', 'composant', 'VERRE')->assertUnprocessable()->assertJsonValidationErrors(['lie_id' => 'VERRE est déjà lié à ce produit.']);
        $this->chez(fn () => ProduitTenant::whereKey($this->p['PILE'])->update(['actif' => false]));
        $this->lier('APPAREIL', 'annexe', 'PILE')->assertUnprocessable()->assertJsonValidationErrors(['lie_id' => "PILE est désactivé : réactivez-le d'abord."]);
        $this->chez(fn () => ProduitTenant::whereKey($this->p['PILE'])->update(['actif' => true]));

        // Annexe : 2 piles avec chaque lampe ; équivalent dans les deux sens, remarque comprise.
        $this->lier('APPAREIL', 'annexe', 'PILE', ['quantite' => 2])->assertCreated();
        $equivalent = $this->lier('BIERE', 'equivalent', 'BIERE2', ['remarque' => 'Même format'])->assertCreated()->json();
        $this->getJson("/api/produits/{$this->p['BIERE2']}/liens")->assertOk()
            ->assertJsonPath('equivalents.0.produit.numero', 'BIERE')->assertJsonPath('equivalents.0.remarque', 'Même format');
        $this->putJson("/api/produits/{$this->p['BIERE']}/liens/{$equivalent['id']}", ['remarque' => 'Même format, autre marque'])->assertOk();
        $this->getJson("/api/produits/{$this->p['BIERE2']}/liens")->assertJsonPath('equivalents.0.remarque', 'Même format, autre marque');
        // La bière fait partie du pack ; la liste des produits donne annexes et composants.
        $this->getJson("/api/produits/{$this->p['BIERE']}/liens")->assertJsonPath('ensembles.0.numero', 'PACK')->assertJsonPath('ensembles.0.quantite', 6);
        $produits = collect($this->getJson('/api/produits')->assertOk()->json())->keyBy('numero');
        $this->assertEquals([['lie_id' => $this->p['PILE'], 'quantite' => 2]], array_map(fn ($a) => ['lie_id' => $a['lie_id'], 'quantite' => $a['quantite']], $produits['APPAREIL']['annexes']));
        $this->assertCount(2, $produits['PACK']['composants']);

        // Disponibilités : le pack se monte 2 fois (4 verres / 2), le coffret aussi ; rien de réservé.
        $dispo = $this->dispo();
        $this->assertEquals([2, 0, 2, true], [$dispo['PACK']['stock'], $dispo['PACK']['reserve'], $dispo['PACK']['disponible'], $dispo['PACK']['ensemble']]);
        $this->assertEquals([2, 2], [$dispo['COFFRET']['stock'], $dispo['COFFRET']['disponible']]);
        $this->assertArrayNotHasKey('ensemble', $dispo['BIERE']);

        // Une commande de 3 coffrets réserve 18 bières, 6 verres, 9 chips : il manque 2 verres, donc 1 coffret.
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-17',
            'lignes' => [['produit_id' => $this->p['COFFRET'], 'quantite' => 3, 'prix_unitaire_ht' => 2000, 'taux_tva' => 0]]])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$commande}/soumettre")->assertOk();
        $manques = $this->postJson("/api/commandes-client/{$commande}/accepter")->assertOk()->json('manques');
        $this->assertEquals([['COFFRET', 3, 1]], array_map(fn ($m) => [$m['numero'], $m['commande'], $m['manque']], $manques));
        $dispo = $this->dispo();
        $this->assertEquals([18, 12], [$dispo['BIERE']['reserve'], $dispo['BIERE']['disponible']]);
        $this->assertEquals([6, -2, 2], [$dispo['VERRE']['reserve'], $dispo['VERRE']['disponible'], $dispo['VERRE']['manque']]);
        $this->assertEquals([9, 1], [$dispo['CHIPS']['reserve'], $dispo['CHIPS']['disponible']]);
        $this->assertEquals([3, -1, 1], [$dispo['COFFRET']['reserve'], $dispo['COFFRET']['disponible'], $dispo['COFFRET']['manque']]);
        $this->assertEquals(-1, $dispo['PACK']['disponible']);

        // Une seconde commande de 15 bières : il en manque 3 ; la bière blonde, équivalente, en a 8.
        $autre = $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-17',
            'lignes' => [['produit_id' => $this->p['BIERE'], 'quantite' => 15, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]]])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$autre}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$autre}/accepter")->assertOk();
        $biere = $this->dispo()['BIERE'];
        $this->assertEquals([33, -3], [$biere['reserve'], $biere['disponible']]);
        $this->assertEquals([['BIERE2', 8, 'Même format, autre marque']], array_map(fn ($e) => [$e['numero'], $e['disponible'], $e['remarque']], $biere['equivalents']));

        // Retirer l'équivalent le retire des deux côtés ; retirer un composant change l'ensemble.
        $this->deleteJson("/api/produits/{$this->p['BIERE']}/liens/{$equivalent['id']}")->assertOk();
        $this->getJson("/api/produits/{$this->p['BIERE2']}/liens")->assertJsonCount(0, 'equivalents');
        $chips = collect($this->getJson("/api/produits/{$this->p['COFFRET']}/liens")->json('composants'))->firstWhere('produit.numero', 'CHIPS');
        $this->putJson("/api/produits/{$this->p['COFFRET']}/liens/{$chips['id']}", ['quantite' => 1])->assertOk()->assertJsonPath('quantite', 1);
    }

    public function test_droits_et_isolation(): void
    {
        $lien = $this->lier('PACK', 'composant', 'BIERE', ['quantite' => 6])->assertCreated()->json();
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['produits.voir' => true, 'produits.modifier' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson("/api/produits/{$this->p['PACK']}/liens")->assertOk()->assertJsonCount(1, 'composants');
        $this->lier('PACK', 'composant', 'VERRE')->assertForbidden();
        $this->putJson("/api/produits/{$this->p['PACK']}/liens/{$lien['id']}", ['quantite' => 12])->assertForbidden();
        $this->deleteJson("/api/produits/{$this->p['PACK']}/liens/{$lien['id']}")->assertForbidden();

        $autre = Tenant::factory()->create();
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/produits/{$this->p['PACK']}/liens")->assertNotFound();
        $this->deleteJson("/api/produits/{$this->p['PACK']}/liens/{$lien['id']}")->assertNotFound();
        // Un produit d'une autre entreprise ne se lie pas.
        $sien = app(TenantContext::class)->runAsTenant($autre->id, fn () => ProduitTenant::create(['numero' => 'X', 'description' => 'X', 'mesure' => 'UN', 'actif' => true])->id);
        $this->postJson("/api/produits/{$sien}/liens", ['type' => 'composant', 'lie_id' => $this->p['BIERE']])->assertUnprocessable()
            ->assertJsonValidationErrors(['lie_id' => 'Produit introuvable.']);
    }
}
