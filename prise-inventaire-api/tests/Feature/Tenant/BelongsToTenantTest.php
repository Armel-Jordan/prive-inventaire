<?php

namespace Tests\Feature\Tenant;

use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fixtures\TenantFoo;
use Tests\TestCase;

/**
 * Valide le mécanisme du trait BelongsToTenant + TenantScope sur un modèle jetable.
 */
class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_foos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('label')->nullable();
        });
    }

    private function seedTwoTenants(): void
    {
        // En mode super-admin, la création exige un tenant_id explicite.
        app(TenantContext::class)->markSuperAdmin();
        TenantFoo::create(['tenant_id' => 1, 'label' => 'a1']);
        TenantFoo::create(['tenant_id' => 1, 'label' => 'a2']);
        TenantFoo::create(['tenant_id' => 2, 'label' => 'b1']);
    }

    public function test_read_is_filtered_by_current_tenant(): void
    {
        $this->seedTwoTenants();
        $context = app(TenantContext::class);

        $context->setTenantId(1);
        $this->assertSame(2, TenantFoo::count());

        $context->setTenantId(2);
        $this->assertSame(1, TenantFoo::count());
    }

    public function test_super_admin_sees_all(): void
    {
        $this->seedTwoTenants();
        app(TenantContext::class)->markSuperAdmin();

        $this->assertSame(3, TenantFoo::count());
    }

    public function test_unresolved_context_is_fail_closed(): void
    {
        $this->seedTwoTenants();
        app(TenantContext::class)->reset();

        $this->assertSame(0, TenantFoo::count(), 'Sans tenant résolu, aucune ligne ne doit remonter.');
    }

    public function test_without_tenant_scope_bypasses_filter(): void
    {
        $this->seedTwoTenants();
        app(TenantContext::class)->setTenantId(1);

        $this->assertSame(3, TenantFoo::withoutTenantScope()->count());
    }

    public function test_creating_injects_current_tenant(): void
    {
        app(TenantContext::class)->setTenantId(5);

        $foo = TenantFoo::create(['label' => 'x']);

        $this->assertSame(5, (int) $foo->tenant_id);
    }

    public function test_creating_overrides_spoofed_tenant_id(): void
    {
        app(TenantContext::class)->setTenantId(5);

        $foo = TenantFoo::create(['tenant_id' => 999, 'label' => 'spoof']);

        $this->assertSame(5, (int) $foo->tenant_id, 'Le tenant_id fourni par le client doit être écrasé.');
    }

    public function test_creating_without_resolved_context_throws(): void
    {
        app(TenantContext::class)->reset();

        $this->expectException(RuntimeException::class);
        TenantFoo::create(['label' => 'orphan']);
    }

    public function test_creating_as_super_admin_requires_explicit_tenant_id(): void
    {
        app(TenantContext::class)->markSuperAdmin();

        $this->expectException(RuntimeException::class);
        TenantFoo::create(['label' => 'no-tenant']);
    }
}
