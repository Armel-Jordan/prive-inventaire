<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\Verrous;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse la modification d'une fiche qu'un autre compte est en train de modifier (phase 6.3) : `verrou:client,id` sur
 * la route de mise à jour (entité, paramètre de route de la fiche).
 */
class RespecterVerrou
{
    public function __construct(private readonly Verrous $verrous) {}

    public function handle(Request $request, Closure $next, string $entite, string $parametre = 'id'): Response
    {
        $valeur = $request->route($parametre);
        $id = $valeur instanceof Model ? (int) $valeur->getKey() : (int) $valeur;
        $compte = $request->user();
        if ($compte instanceof AdminUser && $id > 0 && ($verrou = $this->verrous->autre($entite, $id, $compte))) {
            return response()->json(['message' => Verrous::message($verrou), 'verrou' => Verrous::presenter($verrou)], 409);
        }

        return $next($request);
    }
}
