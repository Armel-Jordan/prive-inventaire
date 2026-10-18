<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue des messages de l'API (plan de parité fripv2, phase 6.8) : celle de l'en-tête Accept-Language que les écrans
 * envoient (français ou anglais), le français sinon. Les messages du cadre (validation…) suivent cette langue.
 */
class DefinirLangue
{
    public const LANGUES = ['fr', 'en'];

    /** Langue de l'application quand l'écran n'en donne pas, et hors requête (tâches planifiées, files d'attente). */
    public const PAR_DEFAUT = 'fr';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->langue($request));

        return $next($request);
    }

    private function langue(Request $request): string
    {
        // Langues de l'en-tête, la préférée d'abord (« en-US,en;q=0.9,fr;q=0.8 »).
        foreach ($request->getLanguages() as $langue) {
            $code = strtolower(substr($langue, 0, 2));
            if (in_array($code, self::LANGUES, true)) {
                return $code;
            }
        }

        return self::PAR_DEFAUT;
    }
}
