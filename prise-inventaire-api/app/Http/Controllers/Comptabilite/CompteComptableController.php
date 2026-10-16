<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Services\Comptabilite\PlanComptable;
use App\Support\PlansComptables;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Plan comptable de l'entreprise : comptes, plans types, import et export CSV. Consultation avec le module
 * Finance ; modifications par les administrateurs et responsables (routes).
 */
class CompteComptableController extends Controller
{
    public function __construct(private PlanComptable $plan) {}

    /** Tous les comptes, triés par numéro : l'écran en déduit l'arborescence. */
    public function index(): JsonResponse
    {
        return response()->json(CompteComptable::orderBy('numero')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request) + ['actif' => true, 'saisie_manuelle' => true];
        if ($refus = $this->plan->controler($donnees)) {
            return $this->refus($refus);
        }

        return response()->json(CompteComptable::create($this->normaliser($donnees)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $compte = CompteComptable::findOrFail($id);
        $donnees = $this->valider($request, $compte) + $compte->only(['actif', 'saisie_manuelle']);
        if ($refus = $this->plan->controler($donnees, $compte)) {
            return $this->refus($refus);
        }
        $compte->update($this->normaliser($donnees));

        return response()->json($compte->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $compte = CompteComptable::findOrFail($id);
        if ($raison = $this->plan->refusSuppression($compte)) {
            return response()->json(['message' => $raison], 422);
        }
        $compte->delete();

        return response()->json(['message' => 'Compte supprimé.']);
    }

    /** Plans types proposés à la mise en place. */
    public function plans(): JsonResponse
    {
        return response()->json(collect(PlansComptables::PLANS)->map(fn (array $plan, string $code) => [
            'code' => $code,
            'libelle' => $plan[0],
            'description' => $plan[1],
            'nombre_comptes' => count(PlansComptables::comptes($code)),
        ])->values());
    }

    /** Crée les comptes d'un plan type ; « completer » n'ajoute que les comptes absents d'un plan existant. */
    public function importerPlan(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'plan' => ['required', Rule::in(PlansComptables::codes())],
            'completer' => ['sometimes', 'boolean'],
        ]);
        $completer = (bool) ($donnees['completer'] ?? false);
        if (! $completer && CompteComptable::exists()) {
            return response()->json(['message' => 'Le plan comptable contient déjà des comptes : choisissez de le compléter pour n\'ajouter que les comptes manquants.'], 422);
        }
        $crees = $this->plan->importerPlan($donnees['plan'], $completer);

        return response()->json([
            'crees' => $crees,
            'message' => $crees === 0 ? 'Aucun compte à ajouter : le plan contient déjà tous ces comptes.' : "{$crees} compte(s) créé(s).",
        ]);
    }

    public function importerFichier(Request $request): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:2048', 'mimes:csv,txt']], [
            'fichier.mimes' => 'Le fichier doit être un CSV (séparateur point-virgule, virgule ou tabulation).',
        ]);
        $resultat = $this->plan->importerFichier((string) file_get_contents($request->file('fichier')->getRealPath()));
        if ($resultat['erreurs']) {
            return response()->json([
                'message' => 'Le fichier contient des erreurs : aucun compte n\'a été créé.',
                'erreurs' => $resultat['erreurs'],
            ], 422);
        }
        $message = "{$resultat['crees']} compte(s) créé(s)".($resultat['ignores'] ? ", {$resultat['ignores']} déjà au plan (ignoré(s))" : '').'.';

        return response()->json($resultat + ['message' => $message]);
    }

    public function exporter(): Response
    {
        return response($this->plan->exporter(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plan-comptable.csv"',
        ]);
    }

    private function valider(Request $request, ?CompteComptable $compte = null): array
    {
        $request->merge(['numero' => preg_replace('/\s+/', '', (string) $request->input('numero'))]);

        return $request->validate([
            'numero' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/', TenantRule::unique('comptes_comptables', 'numero', $compte?->id)],
            'libelle' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', TenantRule::exists('comptes_comptables')],
            'nature' => ['required', Rule::in(CompteComptable::NATURES)],
            'type' => ['required', Rule::in(CompteComptable::TYPES)],
            'saisie_manuelle' => ['sometimes', 'boolean'],
            'actif' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'numero.unique' => 'Ce numéro de compte existe déjà.',
            'numero.regex' => 'Le numéro ne peut contenir que des chiffres, des lettres, des points et des tirets.',
        ]);
    }

    /** Un en-tête ne reçoit jamais d'écriture directe. */
    private function normaliser(array $donnees): array
    {
        $donnees['libelle'] = trim($donnees['libelle']);
        $donnees['saisie_manuelle'] = $donnees['type'] !== CompteComptable::EN_TETE && (bool) $donnees['saisie_manuelle'];

        return $donnees;
    }

    /** @param  array<string, string>  $erreurs */
    private function refus(array $erreurs): JsonResponse
    {
        return response()->json(['message' => reset($erreurs), 'errors' => array_map(fn (string $m) => [$m], $erreurs)], 422);
    }
}
