<?php

namespace Tests\Unit;

use App\Support\TenantContext;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    public function test_starts_unresolved(): void
    {
        $context = new TenantContext;

        $this->assertFalse($context->isResolved());
        $this->assertFalse($context->isSuperAdmin());
        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenantId());
    }

    public function test_set_tenant_id(): void
    {
        $context = new TenantContext;
        $context->setTenantId(42);

        $this->assertTrue($context->isResolved());
        $this->assertTrue($context->hasTenant());
        $this->assertFalse($context->isSuperAdmin());
        $this->assertSame(42, $context->getTenantId());
    }

    public function test_mark_super_admin(): void
    {
        $context = new TenantContext;
        $context->setTenantId(42);
        $context->markSuperAdmin();

        $this->assertTrue($context->isResolved());
        $this->assertTrue($context->isSuperAdmin());
        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenantId());
    }

    public function test_reset(): void
    {
        $context = new TenantContext;
        $context->setTenantId(7);
        $context->reset();

        $this->assertFalse($context->isResolved());
        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenantId());
    }

    public function test_run_as_tenant_restores_previous_state(): void
    {
        $context = new TenantContext;
        $context->setTenantId(1);

        $seen = $context->runAsTenant(2, function () use ($context) {
            return $context->getTenantId();
        });

        $this->assertSame(2, $seen);
        $this->assertSame(1, $context->getTenantId(), 'Le contexte doit être restauré après runAsTenant.');
    }

    public function test_run_without_tenant_disables_scope_then_restores(): void
    {
        $context = new TenantContext;
        $context->setTenantId(1);

        $wasSuperAdmin = $context->runWithoutTenant(fn () => $context->isSuperAdmin());

        $this->assertTrue($wasSuperAdmin, 'runWithoutTenant doit désactiver le filtre pendant l’exécution.');
        $this->assertFalse($context->isSuperAdmin());
        $this->assertSame(1, $context->getTenantId(), 'Le contexte doit être restauré après runWithoutTenant.');
    }
}
