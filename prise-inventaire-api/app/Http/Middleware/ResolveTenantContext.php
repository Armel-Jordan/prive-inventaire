<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Models\SuperAdmin;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alimente le TenantContext à partir de l'utilisateur AUTHENTIFIÉ (jamais du slug).
 * À poser APRÈS `auth:sanctum` (et après `tenant` si présent, pour le durcissement).
 *
 *  - SuperAdmin              -> contexte super-admin (voit tout).
 *  - AdminUser (+ tenant_id) -> contexte scopé sur SON tenant.
 *
 * Durcissement : si un X-Tenant-Slug est fourni et ne correspond pas au tenant du
 * token, la requête est rejetée (403) — empêche un admin d'agir sur un autre tenant.
 */
class ResolveTenantContext
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof SuperAdmin) {
            $this->context->markSuperAdmin();

            return $next($request);
        }

        if ($user instanceof AdminUser && $user->tenant_id) {
            $this->context->setTenantId((int) $user->tenant_id);

            $slugTenant = $request->attributes->get('tenant');
            if ($slugTenant && (int) $slugTenant->id !== (int) $user->tenant_id) {
                return response()->json([
                    'message' => 'Accès tenant non autorisé',
                    'status' => 403,
                ], 403);
            }

            return $next($request);
        }

        return $next($request);
    }
}
