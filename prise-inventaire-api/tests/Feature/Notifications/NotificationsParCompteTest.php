<?php

namespace Tests\Feature\Notifications;

use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notifications par compte (anomalie A4) : chacun ne voit que celles de toute l'entreprise et celles qui le visent,
 * selon ses droits (une alerte de stock demande de voir les alertes) ; « lue » et « supprimée » valent pour lui seul ;
 * une notification déjà lue avant ce changement reste lue pour tous ; isolation entre entreprises.
 */
class NotificationsParCompteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $paul;

    private AdminUser $awa;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        // Paul : administrateur (tous les droits) ; Awa : rôle « user », sans le module des alertes de stock.
        $this->paul = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->awa = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->ids = $this->chez(fn () => [
            'commune' => Notification::create(['type' => 'information', 'titre' => 'Pour tous', 'message' => 'Message'])->id,
            'stock' => Notification::create(['type' => 'alerte_stock', 'titre' => 'Stock bas', 'message' => 'Ciment'])->id,
            'paul' => Notification::create(['type' => 'information', 'titre' => 'Pour Paul', 'message' => 'Message', 'admin_user_id' => $this->paul->id])->id,
            'ancienne' => Notification::create(['type' => 'information', 'titre' => 'Lue avant', 'message' => 'Message', 'lu' => true])->id,
        ]);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** @return array<string, bool> titre => lue */
    private function liste(): array
    {
        return collect($this->getJson('/api/notifications')->assertOk()->json())->mapWithKeys(fn ($n) => [$n['titre'] => $n['lu']])->all();
    }

    private function nonLues(): int
    {
        return $this->getJson('/api/notifications/unread-count')->assertOk()->json('count');
    }

    public function test_chacun_voit_selon_ses_droits_et_ce_qui_le_vise(): void
    {
        $this->agir($this->paul);
        $this->assertEqualsCanonicalizing(['Pour tous' => false, 'Stock bas' => false, 'Pour Paul' => false, 'Lue avant' => true], $this->liste());
        $this->assertSame(3, $this->nonLues());

        $this->agir($this->awa);
        $this->assertEqualsCanonicalizing(['Pour tous' => false, 'Lue avant' => true], $this->liste());
        $this->assertSame(1, $this->nonLues());
        $this->postJson("/api/notifications/{$this->ids['paul']}/read")->assertNotFound();
        $this->postJson("/api/notifications/{$this->ids['stock']}/read")->assertNotFound();
    }

    public function test_lue_et_supprimee_valent_pour_le_compte_seul(): void
    {
        $this->agir($this->paul);
        $this->postJson("/api/notifications/{$this->ids['commune']}/read")->assertOk()->assertJsonPath('notification.lu', true);
        $this->assertSame(2, $this->nonLues());

        $this->agir($this->awa);
        $this->assertFalse($this->liste()['Pour tous']);
        $this->deleteJson("/api/notifications/{$this->ids['commune']}")->assertOk();
        $this->assertArrayNotHasKey('Pour tous', $this->liste());
        $this->assertSame(0, $this->nonLues());

        // Toujours là pour Paul, qui marque le reste comme lu sans toucher Awa.
        $this->agir($this->paul);
        $this->assertArrayHasKey('Pour tous', $this->liste());
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->nonLues());
        $this->getJson('/api/dashboard/stats')->assertOk();
    }

    public function test_une_autre_entreprise_ne_voit_rien(): void
    {
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $etranger = AdminUser::factory()->for($autre)->create(['role' => 'admin']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($etranger, ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);

        $this->assertSame([], $this->getJson('/api/notifications')->assertOk()->json());
        $this->postJson("/api/notifications/{$this->ids['commune']}/read")->assertNotFound();
    }
}
