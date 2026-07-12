<?php

namespace Tests\Feature\Tenant;

use App\Models\Fournisseur;
use App\Models\MouvementInventaire;
use App\Models\MouvementVente;
use App\Models\ProduitLocalisation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 2a : modèles inventaire NON couplés à /mobile (créés/lus uniquement en
 * contexte tenant). Vérifie l'application du trait + l'isolation réelle.
 */
class Lot2aIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scope_is_applied_to_all_lot2a_models(): void
    {
        $models = [MouvementInventaire::class, ProduitLocalisation::class, MouvementVente::class, Fournisseur::class];

        foreach ($models as $model) {
            $this->assertArrayHasKey(
                TenantScope::class,
                (new $model)->getGlobalScopes(),
                "{$model} doit porter le TenantScope."
            );
        }
    }

    public function test_fournisseur_reads_are_isolated(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAsTenant($a->id, fn () => Fournisseur::create(['code' => 'F-ALPHA', 'raison_sociale' => 'Alpha SARL', 'actif' => true]));
        $context->runAsTenant($b->id, fn () => Fournisseur::create(['code' => 'F-BETA', 'raison_sociale' => 'Beta SARL', 'actif' => true]));

        $context->setTenantId($a->id);
        $this->assertSame(1, Fournisseur::count());
        $this->assertSame('F-ALPHA', Fournisseur::first()->code);

        $context->markSuperAdmin();
        $this->assertSame(2, Fournisseur::count());
    }

    public function test_cannot_read_foreign_fournisseur_by_id(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $fournisseurB = $context->runAsTenant($b->id, fn () => Fournisseur::create(['code' => 'F-BETA', 'raison_sociale' => 'Beta SARL', 'actif' => true]));

        $context->setTenantId($a->id);
        $this->assertNull(Fournisseur::find($fournisseurB->id));
    }

    public function test_create_injects_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        app(TenantContext::class)->setTenantId($a->id);

        $fournisseur = Fournisseur::create(['code' => 'F-X', 'raison_sociale' => 'X', 'actif' => true, 'tenant_id' => 999999]);

        $this->assertSame($a->id, (int) $fournisseur->tenant_id);
    }
}
