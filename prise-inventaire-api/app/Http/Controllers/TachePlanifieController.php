<?php

namespace App\Http\Controllers;

use App\Models\TacheExecution;
use App\Models\TachePlanifiee;
use App\Services\Planificateur;
use App\Support\TachesPlanifiees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Tâches planifiées de l'entreprise : réglage, exécution manuelle, historique (administrateurs). */
class TachePlanifieController extends Controller
{
    public function __construct(private readonly Planificateur $planificateur) {}

    /** Tâches et état du déclenchement (dernier passage du planificateur ou des requêtes). */
    public function index(): JsonResponse
    {
        return response()->json([
            'taches' => $this->planificateur->taches()->map(fn (TachePlanifiee $tache) => $this->presenter($tache))->values(),
            'dernier_passage' => Cache::get(Planificateur::CLE_DERNIER_PASSAGE),
            'declenchement_requetes' => (bool) config('taches.declenchement_requetes'),
            'fuseau' => config('taches.fuseau') ?: config('app.timezone'),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tache = TachePlanifiee::findOrFail($id);
        $donnees = $request->validate([
            'actif' => ['required', 'boolean'],
            'recurrence' => ['required', Rule::in(TachesPlanifiees::RECURRENCES)],
            'heure' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'jours_semaine' => ['nullable', 'required_if:recurrence,hebdomadaire', 'array', 'min:1'],
            'jours_semaine.*' => ['integer', 'between:1,7', 'distinct'],
            'jour_du_mois' => ['nullable', 'required_if:recurrence,mensuelle', 'integer', 'between:1,31'],
            'prochaine_execution' => ['nullable', 'date'],
            // Paramètres prévus par le catalogue pour cette tâche (ex. délai d'une alerte, en jours).
            'parametres' => ['nullable', 'array'],
            'parametres.jours' => ['sometimes', 'integer', 'between:0,365'],
            // Facturation des abonnements : factures créées émises plutôt qu'en brouillon.
            'parametres.emettre' => ['sometimes', 'boolean'],
        ], [
            'heure.regex' => 'L\'heure doit être au format HH:MM.',
            'jours_semaine.required_if' => 'Choisissez au moins un jour de la semaine.',
            'jour_du_mois.required_if' => 'Indiquez le jour du mois.',
        ]);

        if (array_key_exists('parametres', $donnees)) {
            $prevus = TachesPlanifiees::parametresParDefaut($tache->code);
            $tache->parametres = array_merge($tache->parametres ?? [], array_intersect_key($donnees['parametres'] ?? [], $prevus));
        }

        $tache->fill([
            'actif' => $donnees['actif'],
            'recurrence' => $donnees['recurrence'],
            'heure' => $donnees['heure'],
            'jours_semaine' => $donnees['recurrence'] === 'hebdomadaire' ? array_values(array_map('intval', $donnees['jours_semaine'])) : null,
            'jour_du_mois' => $donnees['recurrence'] === 'mensuelle' ? $donnees['jour_du_mois'] : null,
            // Date saisie dans le fuseau des tâches, rangée dans celui de l'application.
            'prochaine_execution' => $donnees['recurrence'] === 'une_fois' && filled($donnees['prochaine_execution'] ?? null)
                ? Carbon::parse($donnees['prochaine_execution'], config('taches.fuseau') ?: config('app.timezone'))->setTimezone(config('app.timezone'))
                : null,
        ]);

        if ($tache->actif && $tache->recurrence === 'une_fois' && (! $tache->prochaine_execution || $tache->prochaine_execution <= now())) {
            return response()->json(['message' => 'Choisissez une date et une heure à venir pour une exécution unique.', 'errors' => [
                'prochaine_execution' => ['Choisissez une date et une heure à venir pour une exécution unique.'],
            ]], 422);
        }

        // Échéance recalculée à chaque réglage ; aucune pour une tâche inactive.
        $tache->prochaine_execution = $tache->actif ? $this->planificateur->prochaine($tache, now()) : null;
        $tache->save();

        return response()->json($this->presenter($tache));
    }

    /** Exécute la tâche tout de suite, sans changer son échéance planifiée. */
    public function executer(Request $request, int $id): JsonResponse
    {
        $tache = TachePlanifiee::findOrFail($id);
        $execution = $this->planificateur->executer($tache, 'manuel', $request->user()?->id);
        if (! $execution) {
            return response()->json(['message' => 'Cette tâche est déjà en cours d\'exécution.'], 409);
        }

        return response()->json(['execution' => $this->presenterExecution($execution->load('auteur:id,nom')), 'tache' => $this->presenter($tache->refresh())]);
    }

    public function historique(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'tache_id' => ['nullable', 'integer'],
            'resultat' => ['nullable', Rule::in(['succes', 'erreur'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $executions = TacheExecution::with('auteur:id,nom')
            ->when($filtres['tache_id'] ?? null, fn ($q, $id) => $q->where('tache_planifiee_id', $id))
            ->when($filtres['resultat'] ?? null, fn ($q, $resultat) => $q->where('resultat', $resultat))
            ->latest('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json($executions->through(fn (TacheExecution $e) => $this->presenterExecution($e)));
    }

    private function presenter(TachePlanifiee $tache): array
    {
        return $tache->only(['id', 'code', 'actif', 'recurrence', 'heure', 'jours_semaine', 'jour_du_mois', 'dernier_resultat', 'statut']) + [
            'prochaine_execution' => $tache->prochaine_execution?->toIso8601String(),
            'derniere_execution' => $tache->derniere_execution?->toIso8601String(),
            // Paramètres réglables (valeurs par défaut comprises) et module requis par la tâche.
            'parametres' => (object) $tache->parametresEffectifs(),
            'module' => TachesPlanifiees::MODULES[$tache->code] ?? null,
        ];
    }

    private function presenterExecution(TacheExecution $execution): array
    {
        return $execution->only(['id', 'tache_planifiee_id', 'code', 'mode', 'duree_ms', 'resultat', 'message']) + [
            'debut' => $execution->debut?->toIso8601String(),
            'fin' => $execution->fin?->toIso8601String(),
            'auteur' => $execution->auteur?->nom,
        ];
    }
}
