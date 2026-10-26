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
    protected function setUp(): void
    {
        parent::setUp();
        // Les requêtes de test annoncent « en-us » par défaut ; les écrans envoient la langue choisie, le français par
        // défaut. Les tests parlent donc français, sauf ceux qui donnent leur propre en-tête (LangueMessagesTest).
        $this->withHeader('Accept-Language', 'fr');
        // La CI garde le magasin de cache « file » de .env.example : sans ce nettoyage, les compteurs des limites de débit
        // (connexion, envois…) passeraient d'un test à l'autre. En local, le magasin « array » est déjà vide.
        $this->app['cache']->store()->flush();
    }

    /** Retire les en-têtes posés (compte, entreprise…) ; la langue reste le français. */
    public function flushHeaders(): static
    {
        parent::flushHeaders();

        return $this->withHeader('Accept-Language', 'fr');
    }

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
