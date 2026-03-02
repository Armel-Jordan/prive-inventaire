<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        $role = $user->role ?? 'user';

        // Admin a toutes les permissions
        if ($role === 'admin') {
            return $next($request);
        }

        // Vérifier la permission dans la table
        $hasPermission = DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission', $permission)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
