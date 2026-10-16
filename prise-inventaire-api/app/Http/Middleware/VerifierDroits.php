<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\Droits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique aux écritures de l'API la matrice des droits du rôle (voir App\Support\Droits), et refuse un compte
 * désactivé dont le jeton serait encore présenté. À poser après auth:sanctum et le contexte d'entreprise.
 */
class VerifierDroits
{
    public function __construct(private readonly Droits $droits) {}

    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();
        if (! $compte instanceof AdminUser) {
            return $next($request);
        }

        if (! $compte->actif) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 401);
        }

        $regle = $this->droits->regle($request);
        if ($regle !== null && ! $this->droits->permet($compte, ...$regle)) {
            [$module, $action] = $regle;

            return response()->json([
                'message' => $this->droits->messageRefus($module, $action),
                'module' => $module,
                'action' => $action,
            ], 403);
        }

        return $next($request);
    }
}
