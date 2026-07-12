<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\TenantTaxe;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolation cross-tenant sur un vrai modèle de production doté du trait (Lot 1).
 * TenantTaxe est représentatif ; les autres modèles du lot partagent le même trait.
 */
class Lot1IsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTaxe(int $tenantId, string $nom): TenantTaxe
    {
        return app(TenantContext::class)->runAsTenant(
            $tenantId,
            fn () => TenantTaxe::create(['nom' => $nom, 'taux' => 20, 'par_defaut' => false])
        );
    }

    public function test_reads_are_isolated_between_tenants(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->makeTaxe($a->id, 'TVA-A');
        $this->makeTaxe($b->id, 'TVA-B');

        $context = app(TenantContext::class);

        $context->setTenantId($a->id);
        $this->assertSame(1, TenantTaxe::count());
        $this->assertSame('TVA-A', TenantTaxe::first()->nom);

        $context->setTenantId($b->id);
        $this->assertSame(1, TenantTaxe::count());
        $this->assertSame('TVA-B', TenantTaxe::first()->nom);

        $context->markSuperAdmin();
        $this->assertSame(2, TenantTaxe::count());
    }

    public function test_cannot_read_foreign_tenant_record_by_id(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $taxeB = $this->makeTaxe($b->id, 'TVA-B');

        app(TenantContext::class)->setTenantId($a->id);

        // Cœur de la fermeture d'IDOR : find/findOrFail sur l'id d'un autre tenant ne remonte rien.
        $this->assertNull(TenantTaxe::find($taxeB->id));
    }

    public function test_create_injects_current_tenant_and_ignores_spoofed_id(): void
    {
        $a = Tenant::factory()->create();
        app(TenantContext::class)->setTenantId($a->id);

        $taxe = TenantTaxe::create([
            'nom' => 'X',
            'taux' => 5,
            'par_defaut' => false,
            'tenant_id' => 999999, // tentative de spoofing
        ]);

        $this->assertSame($a->id, (int) $taxe->tenant_id);
    }
}
