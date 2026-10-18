<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        // Commandes et tâches planifiées (ex. relance des courriels).
        commands: __DIR__.'/../routes/console.php',
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
            // Droits par module du rôle sur les écritures de l'API (App\Support\Droits).
            'droits' => \App\Http\Middleware\VerifierDroits::class,
            // Fiche en cours de modification par un autre compte : enregistrement refusé (App\Services\Verrous).
            'verrou' => \App\Http\Middleware\RespecterVerrou::class,
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

        // Langue des messages (validation…) : celle de l'en-tête Accept-Language des écrans, le français sinon.
        $middleware->api(prepend: [\App\Http\Middleware\DefinirLangue::class]);

        // API sans page de connexion côté serveur : un appel non authentifié ne doit pas chercher la route
        // « login » (inexistante), ce qui donnait une erreur 500 au lieu d'un 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erreurs de l'API en JSON, même sans en-tête « Accept: application/json » (navigateur, lien direct).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
