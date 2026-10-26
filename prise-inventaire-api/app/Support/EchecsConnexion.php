<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Échecs de connexion par compte (plan de parité, phase 6.1) : après 10 mots de passe faux en 15 minutes pour un même
 * compte, la connexion de ce compte est refusée (429) jusqu'à la fin de ces 15 minutes ; une connexion réussie remet le
 * compte à zéro. S'ajoute à la limite par adresse IP posée sur les routes de connexion. Le blocage reste court : il
 * freine la recherche d'un mot de passe sans fermer longtemps le compte de quelqu'un d'autre.
 */
final class EchecsConnexion
{
    public const MAXIMUM = 10;

    public const DUREE_SECONDES = 900;

    /** Clé d'un compte : le canal (back-office, super-administrateur) et ses identifiants, sans casse. */
    public static function cle(string $canal, string ...$identifiants): string
    {
        return 'echecs-connexion:'.$canal.':'.sha1(mb_strtolower(implode('|', $identifiants)));
    }

    /** Refus 429 si le compte a atteint le maximum d'échecs, sinon null. */
    public static function refus(string $cle): ?JsonResponse
    {
        if (! RateLimiter::tooManyAttempts($cle, self::MAXIMUM)) {
            return null;
        }
        $minutes = max(1, (int) ceil(RateLimiter::availableIn($cle) / 60));

        return response()->json([
            'success' => false,
            'message' => __('Trop de tentatives de connexion échouées : réessayez dans :minutes minute(s).', ['minutes' => $minutes]),
        ], 429);
    }

    public static function echec(string $cle): void
    {
        RateLimiter::hit($cle, self::DUREE_SECONDES);
    }

    public static function reussite(string $cle): void
    {
        RateLimiter::clear($cle);
    }
}
