<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\PortailJeton;
use App\Models\PortailParametres;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session du portail client : jeton propre au portail (un jeton d'employé n'y est pas reconnu, et un jeton du
 * portail n'ouvre aucune route des employés). Fixe le contexte de l'entreprise du compte et refuse l'accès si
 * le compte, son client ou l'entreprise sont désactivés, ou si le portail est fermé.
 */
class AuthentifierPortail
{
    public function handle(Request $request, Closure $next): Response
    {
        $clair = $request->bearerToken();
        $jeton = $clair
            ? PortailJeton::with('compte')->where('jeton', hash('sha256', $clair))->where('expire_le', '>', now())->first()
            : null;
        $compte = $jeton?->compte;
        $tenant = $compte ? Tenant::find($compte->tenant_id) : null;
        if (! $compte || ! $compte->actif || ! $tenant?->actif || $tenant->isExpired() || ! $tenant->hasModule('ventes')) {
            return response()->json(['message' => 'Votre session a expiré : reconnectez-vous.'], 401);
        }

        app(TenantContext::class)->setTenantId((int) $tenant->id);
        if (! PortailParametres::courants()->actif) {
            return response()->json(['message' => 'Le portail est fermé pour le moment.', 'portail_ferme' => true], 403);
        }
        if (! Client::where('id', $compte->client_id)->where('actif', true)->exists()) {
            return response()->json(['message' => 'Votre accès au portail est suspendu.'], 401);
        }

        // Dernière utilisation : au plus une écriture par minute.
        if (! $jeton->derniere_utilisation || $jeton->derniere_utilisation->lt(now()->subMinute())) {
            $jeton->forceFill(['derniere_utilisation' => now()])->save();
        }
        $request->attributes->set('portail_compte', $compte);
        $request->attributes->set('portail_jeton', $jeton);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
