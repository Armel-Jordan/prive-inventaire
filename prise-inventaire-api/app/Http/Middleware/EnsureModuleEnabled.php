<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque l'accès aux routes d'un module fonctionnel non souscrit par l'entreprise.
 * Usage : ->middleware('module:ventes'). À poser dans le groupe tenant (le tenant
 * courant est déjà résolu par IdentifyTenant).
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant && ! $tenant->hasModule($module)) {
            return response()->json([
                'message' => "Le module « {$module} » n'est pas activé pour cette entreprise.",
                'module' => $module,
                'status' => 403,
            ], 403);
        }

        return $next($request);
    }
}
