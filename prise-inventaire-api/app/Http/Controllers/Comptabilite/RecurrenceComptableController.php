<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\EcritureComptable;
use App\Models\RecurrenceComptable;
use App\Models\RecurrenceEcheance;
use App\Services\Comptabilite\Recurrences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Écritures récurrentes : planifier la répétition d'une écriture manuelle, voir les récurrences et leurs dates,
 * convertir les dates échues en écritures, suspendre, reprendre ou supprimer les dates restantes d'une récurrence. Consultation avec le droit
 * de voir la comptabilité ; le reste avec celui de saisir des écritures (voir App\Support\Droits).
 */
class RecurrenceComptableController extends Controller
{
    public function __construct(private readonly Recurrences $recurrences) {}

    /** Récurrences, la plus récente d'abord, avec leurs dates (converties, échues, à venir). */
    public function index(): JsonResponse
    {
        $recurrences = RecurrenceComptable::query()->with($this->recurrences->relations())->orderByDesc('numero')->get();

        return response()->json([
            'recurrences' => $recurrences->map(fn (RecurrenceComptable $r) => $this->presenter($r))->values(),
            'echues' => RecurrenceEcheance::query()->whereNull('ecriture_id')->whereDate('date_prevue', '<=', today())
                ->whereHas('recurrence', fn ($q) => $q->where('active', true))->count(),
        ]);
    }

    /** Planifie la répétition d'une écriture manuelle. */
    public function store(Request $request, int $id): JsonResponse
    {
        $modele = EcritureComptable::findOrFail($id);
        $donnees = $request->validate([
            'date_debut' => ['required', 'date'],
            'nombre' => ['required', 'integer', 'between:1,120'],
            'frequence' => ['required', Rule::in(RecurrenceComptable::FREQUENCES)],
        ]);

        return response()->json($this->presenter($this->recurrences->planifier($modele, $donnees, $request->user()?->id)), 201);
    }

    /** Suspend ou reprend une récurrence. */
    public function update(Request $request, int $id): JsonResponse
    {
        $recurrence = RecurrenceComptable::findOrFail($id);
        $recurrence->update($request->validate(['active' => ['required', 'boolean']]));

        return response()->json($this->presenter($recurrence->load($this->recurrences->relations())));
    }

    /**
     * Supprime les dates non converties d'une récurrence. Si elle a déjà créé des écritures, elle reste, arrêtée, pour
     * que son numéro les désigne toujours au journal ; sinon elle disparaît.
     */
    public function destroy(int $id): JsonResponse
    {
        $recurrence = RecurrenceComptable::findOrFail($id);
        $recurrence->echeances()->whereNull('ecriture_id')->delete();
        if ($recurrence->echeances()->exists()) {
            $recurrence->update(['active' => false]);
        } else {
            $recurrence->delete();
        }

        return response()->json(['message' => __('Récurrence n° :numero supprimée : ses écritures déjà créées restent au journal.', ['numero' => $recurrence->numero])]);
    }

    /** Convertit en écritures les dates échues (à ce jour) ou les dates choisies. */
    public function convertir(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'echeances' => ['nullable', 'array', 'max:500'],
            'echeances.*' => ['integer'],
            'jusqu_au' => ['nullable', 'date'],
        ]);
        $resultat = $this->recurrences->convertir(
            isset($donnees['echeances']) ? array_map('intval', $donnees['echeances']) : null,
            isset($donnees['jusqu_au']) ? Carbon::parse($donnees['jusqu_au']) : today(),
            $request->user()?->id,
        );

        return response()->json($resultat + [
            'message' => $resultat['converties'] > 0
                ? __(':nombre écriture(s) récurrente(s) créée(s).', ['nombre' => $resultat['converties']])
                : __('Aucune écriture à créer.'),
        ]);
    }

    private function presenter(RecurrenceComptable $recurrence): array
    {
        $echeances = $recurrence->echeances;
        $prochaine = $echeances->first(fn (RecurrenceEcheance $e) => ! $e->ecriture_id);

        return $recurrence->toArray() + [
            'converties' => $echeances->whereNotNull('ecriture_id')->count(),
            'echues' => $echeances->filter(fn (RecurrenceEcheance $e) => ! $e->ecriture_id && $e->date_prevue->lte(today()))->count(),
            'prochaine' => $prochaine?->date_prevue?->toDateString(),
        ];
    }
}
