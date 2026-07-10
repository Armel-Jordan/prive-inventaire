<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // De nombreux modèles historiques forcent protected $connection = 'mysql'.
        // En dev local (sqlite), on redirige la connexion 'mysql' vers le sqlite en
        // mémoire et on en fait la connexion par défaut, afin que RefreshDatabase migre
        // exactement la connexion utilisée par les modèles. En CI/MySQL réel, on ne
        // touche à rien : les tests tournent fidèlement sur MySQL.
        if ($app['config']->get('database.default') === 'sqlite') {
            $app['config']->set('database.connections.mysql', $app['config']->get('database.connections.sqlite'));
            $app['config']->set('database.default', 'mysql');
        }

        return $app;
    }

    /** Authentifie un AdminUser (Sanctum) et positionne le TenantContext sur son tenant. */
    protected function actingAsTenant(AdminUser $user): static
    {
        Sanctum::actingAs($user, ['*']);
        app(TenantContext::class)->setTenantId((int) $user->tenant_id);

        return $this;
    }

    /** Authentifie un SuperAdmin (Sanctum) et positionne le TenantContext en mode super-admin. */
    protected function actingAsSuperAdmin(SuperAdmin $admin): static
    {
        Sanctum::actingAs($admin, ['*']);
        app(TenantContext::class)->markSuperAdmin();

        return $this;
    }
}
