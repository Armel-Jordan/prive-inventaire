<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorisation par rôle sur admin_users.role (admin / manager / user).
 * Usage : ->middleware('role:admin') ou ->middleware('role:admin,manager').
 * À poser après auth:sanctum. Complète le gating cosmétique du front par un
 * enforcement réel côté API sur les actions sensibles.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        if (! $role || ! in_array($role, $roles, true)) {
            return response()->json([
                'message' => 'Rôle insuffisant pour cette action.',
                'required_roles' => $roles,
                'status' => 403,
            ], 403);
        }

        return $next($request);
    }
}
