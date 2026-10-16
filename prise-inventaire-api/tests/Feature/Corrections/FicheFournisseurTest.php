<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Détail d'un fournisseur (GET /fournisseurs/{id}) : il répondait toujours 500, la relation produits
 * cherchant une colonne pivot « produit_tenant_id » qui n'existe pas (la table porte « produit_id »).
 */
class FicheFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create();
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    public function test_detail_avec_ses_produits_et_ses_dernieres_commandes(): void
    {
        $acheteur = AdminUser::factory()->for($this->tenant)->create();
        $fournisseurId = $this->chezLeTenant(function () use ($acheteur) {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam']);
            $produit = ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 5000]);
            $fournisseur->produits()->attach($produit->id, ['reference_fournisseur' => 'CPJ-45', 'prix_achat' => 4200, 'fournisseur_principal' => true]);
            ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-16',
                'statut' => 'brouillon', 'montant_total' => 42000, 'created_by' => $acheteur->id,
            ]);

            return $fournisseur->id;
        });

        $this->getJson("/api/fournisseurs/{$fournisseurId}")->assertOk()
            ->assertJsonPath('raison_sociale', 'Cimencam')
            ->assertJsonPath('produits.0.numero', 'CIM-50')
            ->assertJsonPath('produits.0.pivot.reference_fournisseur', 'CPJ-45')
            ->assertJsonCount(1, 'commandes')
            ->assertJsonPath('commandes.0.numero', 'BC-0001');
    }

    public function test_fournisseur_d_une_autre_entreprise_introuvable(): void
    {
        $fournisseurId = $this->chezLeTenant(fn () => Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam'])->id);

        $this->connecter(Tenant::factory()->create(['modules' => ['achats']]));
        $this->getJson("/api/fournisseurs/{$fournisseurId}")->assertNotFound();
    }
}
