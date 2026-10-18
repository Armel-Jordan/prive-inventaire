<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourrielModele;
use App\Services\Courriels\RenduCourriel;
use App\Services\Courriels\VariablesCourriel;
use App\Support\Courriels;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Modèles de courriel de l'entreprise : un par type d'envoi et par langue, avec aperçu. */
class CourrielModeleController extends Controller
{
    public function __construct(
        private readonly VariablesCourriel $variables,
        private readonly RenduCourriel $rendu,
    ) {}

    /** Types d'envoi, variables de chacun et modèles par défaut, pour l'écran de saisie. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'langues' => Courriels::LANGUES,
            'types' => array_map(fn (string $type) => [
                'type' => $type,
                'variables' => Courriels::variables($type),
                'piece_jointe' => isset(Courriels::FICHIERS[$type]),
                'defauts' => array_combine(
                    Courriels::LANGUES,
                    array_map(fn (string $langue) => Courriels::modeleParDefaut($type, $langue), Courriels::LANGUES),
                ),
            ], Courriels::types()),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(CourrielModele::orderBy('type')->orderBy('langue')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->regles() + [
            'type' => ['required', Rule::in(Courriels::types())],
            'langue' => [
                'required',
                Rule::in(Courriels::LANGUES),
                TenantRule::unique('courriel_modeles', 'langue')->where('type', $request->input('type')),
            ],
        ], ['langue.unique' => __('Un modèle existe déjà pour ce type et cette langue.')]);

        return response()->json(CourrielModele::create($donnees), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $modele = CourrielModele::findOrFail($id);
        // Le type et la langue identifient le modèle : ils ne changent plus après sa création.
        $modele->update($request->validate($this->regles()));

        return response()->json($modele);
    }

    public function destroy(int $id): JsonResponse
    {
        CourrielModele::findOrFail($id)->delete();

        return response()->json(['message' => __('Modèle de courriel supprimé.')]);
    }

    /**
     * Rendu du modèle en cours de saisie (même non enregistré) avec le document le plus récent
     * du type, ou des valeurs d'exemple ; les variables inconnues sont signalées et surlignées.
     */
    public function apercu(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(Courriels::types())],
            'langue' => ['required', Rule::in(Courriels::LANGUES)],
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string', 'max:20000'],
            'nom_fichier' => ['nullable', 'string', 'max:255'],
        ]);

        $document = $this->variables->documentRecent($donnees['type']);
        $valeurs = $this->variables->pour($donnees['type'], $document, $donnees['langue']);

        return response()->json($this->rendu->rendre($donnees['type'], $donnees, $valeurs, $donnees['langue'], surligner: true) + [
            'document' => $document?->numero,
        ]);
    }

    private function regles(): array
    {
        return [
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string', 'max:20000'],
            'nom_fichier' => ['nullable', 'string', 'max:255'],
            'repondre_a' => ['nullable', 'email', 'max:255'],
            'accuse_lecture' => ['boolean'],
        ];
    }
}
