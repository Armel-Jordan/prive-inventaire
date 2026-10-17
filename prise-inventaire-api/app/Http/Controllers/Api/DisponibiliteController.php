<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisponibiliteProduits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disponibilité des produits : stock compté, réservé par les commandes client acceptées, disponible, en commande chez
 * les fournisseurs, attendu en reliquat. Lecture seule ; le calcul est dans App\Services\DisponibiliteProduits.
 */
class DisponibiliteController extends Controller
{
    public function __construct(private readonly DisponibiliteProduits $disponibilites) {}

    public function index(Request $request): JsonResponse
    {
        $recherche = mb_strtolower(trim((string) $request->get('recherche', '')));
        $lignes = $this->disponibilites->calculer()
            ->when($recherche !== '', fn ($c) => $c->filter(fn (array $d) => str_contains(mb_strtolower($d['numero'].' '.$d['description']), $recherche)))
            // Produits engagés seulement (réservés, en commande, en reliquat), sauf demande de la liste complète.
            ->when(! $request->boolean('tous'), fn ($c) => $c->filter(fn (array $d) => $d['reserve'] > 0 || $d['en_commande'] > 0 || $d['reliquats'] > 0))
            ->when($request->boolean('manques'), fn ($c) => $c->filter(fn (array $d) => $d['manque'] > 0))
            ->sortByDesc('manque')->values();

        return response()->json([
            'data' => $lignes,
            'resume' => [
                'produits' => $lignes->count(),
                'en_manque' => $lignes->where('manque', '>', 0)->count(),
            ],
        ]);
    }
}
