<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve les routes /super-admin/* aux vrais SuperAdmin.
 * Sans ce garde, un AdminUser authentifié (token d'abonné) pouvait atteindre les
 * endpoints de gestion des tenants (faille d'escalade de privilèges).
 * À poser après `auth:sanctum`.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof SuperAdmin) {
            return response()->json([
                'message' => 'Accès réservé au super-administrateur.',
                'status' => 403,
            ], 403);
        }

        return $next($request);
    }
}
