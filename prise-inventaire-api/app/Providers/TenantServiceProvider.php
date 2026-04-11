<?php

namespace App\Providers;

use App\Services\TenantService;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantService::class, function ($app) {
            return new TenantService;
        });
    }

    public function boot(): void
    {
        //
    }
}
