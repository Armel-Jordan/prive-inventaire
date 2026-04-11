<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function __construct(protected TenantService $tenantService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        // 1. Essayer via header X-Tenant-Slug
        if ($request->hasHeader('X-Tenant-Slug')) {
            $tenant = $this->tenantService->findBySlug($request->header('X-Tenant-Slug'));
        }

        // 2. Essayer via sous-domaine
        if (! $tenant) {
            $host = $request->getHost();
            $parts = explode('.', $host);
            if (count($parts) >= 3) {
                $tenant = $this->tenantService->findBySlug($parts[0]);
            }
        }

        // 3. Essayer via query param (pour dev)
        if (! $tenant && $request->has('tenant')) {
            $tenant = $this->tenantService->findBySlug($request->query('tenant'));
        }

        if (! $tenant) {
            return response()->json([
                'error' => 'Tenant non identifié',
                'message' => 'Veuillez spécifier un tenant valide',
            ], 400);
        }

        if ($tenant->isExpired()) {
            return response()->json([
                'error' => 'Abonnement expiré',
                'message' => 'Votre abonnement a expiré. Veuillez contacter le support.',
            ], 403);
        }

        $this->tenantService->setTenant($tenant);

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
