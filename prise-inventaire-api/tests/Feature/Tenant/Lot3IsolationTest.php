<?php

namespace Tests\Feature\Tenant;

use App\Models\Approbation;
use App\Models\BonLivraison;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Tournee;
use App\Models\TransfertPlanifie;
use App\Models\ZonePreparation;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 3 : modèles Ventes (tous montés dans le groupe tenant, aucun couplage /mobile).
 */
class Lot3IsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scope_is_applied_to_all_lot3_models(): void
    {
        $models = [
            Client::class, Devis::class, Facture::class, BonLivraison::class,
            Camion::class, Tournee::class, ZonePreparation::class,
            ComClientEntete::class, ComFourEntete::class, Approbation::class, TransfertPlanifie::class,
        ];

        foreach ($models as $model) {
            $this->assertArrayHasKey(
                TenantScope::class,
                (new $model)->getGlobalScopes(),
                "{$model} doit porter le TenantScope."
            );
        }
    }

    public function test_camion_reads_are_isolated(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAsTenant($a->id, fn () => Camion::create(['immatriculation' => 'AA-001-AA']));
        $context->runAsTenant($b->id, fn () => Camion::create(['immatriculation' => 'BB-002-BB']));

        $context->setTenantId($a->id);
        $this->assertSame(1, Camion::count());
        $this->assertSame('AA-001-AA', Camion::first()->immatriculation);

        $context->markSuperAdmin();
        $this->assertSame(2, Camion::count());
    }

    public function test_cannot_read_foreign_camion_by_id(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $camionB = $context->runAsTenant($b->id, fn () => Camion::create(['immatriculation' => 'BB-002-BB']));

        $context->setTenantId($a->id);
        $this->assertNull(Camion::find($camionB->id));
    }

    public function test_create_injects_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        app(TenantContext::class)->setTenantId($a->id);

        $camion = Camion::create(['immatriculation' => 'CC-003-CC', 'tenant_id' => 999999]);

        $this->assertSame($a->id, (int) $camion->tenant_id);
    }
}
