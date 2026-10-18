<?php

namespace App\Http\Middleware;

use App\Support\PermissionsAnciennes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => __('Non authentifié'),
            ], 401);
        }

        $role = $user->role ?? 'user';

        // Admin a toutes les permissions
        if ($role === 'admin') {
            return $next($request);
        }

        // Vérifier la permission (permissions héritées « rôle + permission »)
        $hasPermission = PermissionsAnciennes::pour($role)->contains($permission);

        if (! $hasPermission) {
            return response()->json([
                'success' => false,
                'message' => __('Permission refusée'),
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
