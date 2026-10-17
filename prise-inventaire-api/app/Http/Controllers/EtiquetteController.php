<?php

namespace App\Http\Controllers;

use App\Models\EtiquetteFormat;
use App\Services\Etiquettes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Étiquettes des produits : formats (rouleau ou planche, contenu) et impression en PDF. */
class EtiquetteController extends Controller
{
    public function __construct(private readonly Etiquettes $etiquettes) {}

    /** Les formats (ceux d'origine au premier appel), avec ce qu'une étiquette peut porter et les pages des planches. */
    public function formats(): JsonResponse
    {
        return response()->json([
            'formats' => $this->etiquettes->formats(),
            'contenus' => Etiquettes::CONTENUS,
            'pages' => Etiquettes::PAGES,
            'maximum' => Etiquettes::MAXIMUM,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->etiquettes->enregistrer(null, $this->valider($request, true)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $format = EtiquetteFormat::query()->findOrFail($id);

        return response()->json($this->etiquettes->enregistrer($format, $this->valider($request, false)));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->etiquettes->supprimer(EtiquetteFormat::query()->findOrFail($id));

        return response()->json(['message' => 'Format retiré.']);
    }

    /** Le PDF des étiquettes : produits et exemplaires, liste de prix, position de départ sur une planche entamée. */
    public function imprimer(Request $request): Response
    {
        $donnees = $request->validate([
            'format_id' => ['required', 'integer'],
            'lignes' => ['required', 'array', 'min:1', 'max:500'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.code_barre_id' => ['nullable', 'integer'],
            'lignes.*.copies' => ['required', 'integer', 'min:1', 'max:'.Etiquettes::MAXIMUM],
            'liste_prix_id' => ['nullable', 'integer'],
            'groupe_taxe_id' => ['nullable', 'integer'],
            'depart' => ['nullable', 'integer', 'min:1'],
            'langue' => ['nullable', 'in:fr,en'],
            'contours' => ['nullable', 'boolean'],
        ]);
        $format = EtiquetteFormat::query()->where('actif', true)->find($donnees['format_id']);
        if (! $format) {
            throw ValidationException::withMessages(['format_id' => 'Format introuvable ou désactivé.']);
        }
        $nombre = array_sum(array_map(fn (array $ligne) => (int) $ligne['copies'], $donnees['lignes']));

        return $this->etiquettes->pdf($format, $donnees['lignes'], $donnees)->stream($this->etiquettes->nomFichier($format, $nombre));
    }

    private function valider(Request $request, bool $creation): array
    {
        $requis = $creation ? 'required' : 'sometimes';

        return $request->validate([
            'nom' => [$requis, 'string', 'max:100'],
            'support' => [$requis, Rule::in(Etiquettes::SUPPORTS)],
            'largeur_mm' => [$requis, 'numeric', 'min:10', 'max:200'],
            'hauteur_mm' => [$requis, 'numeric', 'min:10', 'max:200'],
            'page' => ['nullable', Rule::in(array_keys(Etiquettes::PAGES))],
            'colonnes' => ['nullable', 'integer', 'min:1', 'max:20'],
            'rangees' => ['nullable', 'integer', 'min:1', 'max:40'],
            'marge_haut_mm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'marge_gauche_mm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'espace_colonnes_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'espace_rangees_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'contenu' => [$requis, 'array'],
            'contenu.*' => ['string', Rule::in(Etiquettes::CONTENUS)],
            'taille_police' => ['nullable', 'integer', 'min:5', 'max:16'],
            'par_defaut' => ['sometimes', 'boolean'],
            'actif' => ['sometimes', 'boolean'],
            'ordre' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
