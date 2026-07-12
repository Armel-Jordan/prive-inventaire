<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\IdentifyTenant::class,
            'tenant.context' => \App\Http\Middleware\ResolveTenantContext::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'super-admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'role' => \App\Http\Middleware\RequireRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // Les routes tenant utilisent le route-model binding ({devis}, {commande}…).
        // SubstituteBindings doit résoudre le modèle APRÈS que le contexte tenant
        // soit posé — sinon le TenantScope est fail-closed (whereRaw 1=0) et toute
        // route à binding renvoie 404. On force donc IdentifyTenant puis
        // ResolveTenantContext à passer avant SubstituteBindings (et après auth).
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IdentifyTenant::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolveTenantContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
