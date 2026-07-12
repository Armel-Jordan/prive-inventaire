<?php

namespace App\Providers;

use App\Services\TenantService;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantService::class, function ($app) {
            return new TenantService;
        });

        // Contexte tenant courant, partagé pour toute la requête/processus.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
