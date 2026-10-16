<?php

namespace App\Http\Controllers\Inventaire;

use App\Http\Controllers\Controller;
use App\Services\ValorisationInventaire;
use App\Support\FichierCsv;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Valorisation de l'inventaire officiel, par produit et par secteur, au coût d'achat ou au prix de vente. Réservée
 * aux administrateurs et responsables (routes) : elle révèle les coûts.
 */
class ValorisationInventaireController extends Controller
{
    public function __invoke(Request $request, ValorisationInventaire $valorisation): JsonResponse|Response
    {
        $filtres = $request->validate([
            'methode' => ['nullable', Rule::in(ValorisationInventaire::METHODES)],
            'secteur_id' => ['nullable', 'integer', TenantRule::exists('secteurs')],
        ]);
        $resultat = $valorisation->calculer($filtres['methode'] ?? 'cout_moyen', $filtres['secteur_id'] ?? null);
        if ($request->query('format') !== 'csv') {
            return response()->json($resultat);
        }

        $lignes = array_map(fn (array $p) => [
            $p['numero'], $p['description'], $p['categorie'], implode(', ', $p['secteurs']), (float) $p['quantite'],
            $p['cout_unitaire'] !== null ? (float) $p['cout_unitaire'] : '', $p['valeur'] !== null ? (float) $p['valeur'] : '', $p['reference'],
        ], $resultat['produits']);
        $lignes[] = ['', 'Total', '', '', '', '', (float) $resultat['total'], ''];

        return FichierCsv::reponse("valorisation-inventaire-{$resultat['methode']}.csv",
            ['produit', 'description', 'categorie', 'secteurs', 'quantite', 'cout_unitaire', 'valeur', 'reference'], $lignes);
    }
}
