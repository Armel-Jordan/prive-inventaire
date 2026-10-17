<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Codes-barres des produits (phase 2.1) : ajout (type deviné, clé contrôlée), code principal unique, quantité par
 * lecture, codes en double ou égaux au numéro d'un autre produit refusés, retrait ; lecture par l'application mobile
 * (validation et scan rapportés au produit, à la quantité du code) ; recherche globale ; droits et isolation.
 */
class CodesBarresProduitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $ciment;

    private int $fer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(function () {
            $this->ciment = ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100, 'mesure' => 'SAC', 'actif' => true])->id;
            $this->fer = ProduitTenant::create(['numero' => 'FER-12', 'description' => 'Fer à béton 12 mm', 'prix_unitaire' => 50, 'mesure' => 'BARRE', 'actif' => true])->id;
        });
        $this->agir($this->admin);
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

    private function ajouter(int $produit, array $donnees): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/produits/{$produit}/codes-barres", $donnees);
    }

    public function test_codes_lecture_mobile_et_recherche(): void
    {
        // Le premier code est principal d'office ; le type se devine ; la clé d'un EAN est contrôlée.
        $ean = $this->ajouter($this->ciment, ['code' => '4006381333931'])->assertCreated()->json();
        $this->assertEquals(['ean13', true, 1], [$ean['type'], $ean['principal'], $ean['quantite']]);
        $this->ajouter($this->ciment, ['code' => '4006381333932', 'type' => 'ean13'])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'La clé de contrôle du code EAN-13 est fausse : le dernier chiffre devrait être 1.']);
        $carton = $this->ajouter($this->ciment, ['code' => 'CARTON-CIM-50', 'quantite' => 12])->assertCreated()->json();
        $this->assertEquals(['code128', false, 12], [$carton['type'], $carton['principal'], $carton['quantite']]);
        // Un nouveau principal retire l'ancien.
        $this->ajouter($this->ciment, ['code' => '96385074', 'principal' => true])->assertCreated()->assertJsonPath('type', 'ean8');
        // Ni en double, ni le numéro d'un autre produit.
        $this->ajouter($this->fer, ['code' => '4006381333931'])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Le code 4006381333931 est déjà celui du produit CIM-50.']);
        $this->ajouter($this->ciment, ['code' => 'FER-12'])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => "Le code FER-12 est le numéro d'un autre produit : une lecture serait ambiguë."]);
        $this->putJson("/api/produits/{$this->ciment}/codes-barres/{$carton['id']}", ['code' => 'CARTON-CIM-50', 'quantite' => 24])->assertOk()->assertJsonPath('quantite', '24.000');
        $codes = $this->getJson("/api/produits/{$this->ciment}/codes-barres")->assertOk()->json();
        $this->assertSame([['96385074', true], ['4006381333931', false], ['CARTON-CIM-50', false]], array_map(fn ($c) => [$c['code'], $c['principal']], $codes));
        // Retirer le principal : le plus ancien des autres le devient.
        $this->deleteJson("/api/produits/{$this->ciment}/codes-barres/{$codes[0]['id']}")->assertOk();
        $this->assertSame([['4006381333931', true], ['CARTON-CIM-50', false]],
            array_map(fn ($c) => [$c['code'], $c['principal']], $this->getJson("/api/produits/{$this->ciment}/codes-barres")->json()));
        $liste = collect($this->getJson('/api/produits')->assertOk()->json())->firstWhere('numero', 'CIM-50');
        $this->assertSame(['4006381333931', 'CARTON-CIM-50'], array_column($liste['codes_barres'], 'code'));

        // Application mobile : le code lu est rapporté à son produit, à la quantité du code.
        $this->postJson('/api/mobile/produit/valider', ['numero' => 'CARTON-CIM-50'])->assertOk()
            ->assertJsonPath('numero', 'CIM-50')->assertJsonPath('code_lu', 'CARTON-CIM-50')->assertJsonPath('quantite_par_lecture', 24);
        $this->postJson('/api/mobile/produit/valider', ['numero' => 'CIM-50'])->assertOk()->assertJsonPath('code_lu', null)->assertJsonPath('quantite_par_lecture', 1);
        $scan = function (string $numero, float $quantite) {
            $scan = $this->postJson('/api/mobile/scan/enregistrer', ['numero' => $numero, 'quantite' => $quantite, 'employe' => 'E1', 'secteur' => 'A1'])
                ->assertCreated()->json('scan');

            return [$scan['numero'], (float) $scan['quantite'], $scan['code_lu'] ?? null];
        };
        $this->assertSame(['CIM-50', 48.0, 'CARTON-CIM-50'], $scan('CARTON-CIM-50', 2));
        $this->assertSame(['CIM-50', 3.0, null], $scan('CIM-50', 3));
        $this->assertSame(['INCONNU', 1.0, null], $scan('INCONNU', 1));
        $this->assertEquals(51, $this->chez(fn () => (float) ScanTenant::where('numero', 'CIM-50')->sum('quantite')));
        // Le code d'un produit désactivé ne se lit plus.
        $this->chez(fn () => ProduitTenant::whereKey($this->ciment)->update(['actif' => false]));
        $this->postJson('/api/mobile/produit/valider', ['numero' => '4006381333931'])->assertNotFound();
        $this->chez(fn () => ProduitTenant::whereKey($this->ciment)->update(['actif' => true]));

        // Recherche globale : par un code-barres.
        $groupes = $this->getJson('/api/recherche?q=CARTON-CIM&types=produits')->assertOk()->json('groupes');
        $this->assertSame('CIM-50 — Ciment 50 kg', $groupes[0]['resultats'][0]['titre']);
    }

    public function test_droits_et_isolation(): void
    {
        $code = $this->ajouter($this->ciment, ['code' => '4006381333931'])->assertCreated()->json();
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['produits.voir' => true, 'produits.modifier' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson("/api/produits/{$this->ciment}/codes-barres")->assertOk()->assertJsonCount(1);
        $this->ajouter($this->ciment, ['code' => '5901234123457'])->assertForbidden();
        $this->putJson("/api/produits/{$this->ciment}/codes-barres/{$code['id']}", ['code' => '5901234123457'])->assertForbidden();
        $this->deleteJson("/api/produits/{$this->ciment}/codes-barres/{$code['id']}")->assertForbidden();

        $autre = Tenant::factory()->create();
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/produits/{$this->ciment}/codes-barres")->assertNotFound();
        $this->ajouter($this->ciment, ['code' => '5901234123457'])->assertNotFound();
        $this->postJson('/api/mobile/produit/valider', ['numero' => '4006381333931'])->assertNotFound();
    }
}
