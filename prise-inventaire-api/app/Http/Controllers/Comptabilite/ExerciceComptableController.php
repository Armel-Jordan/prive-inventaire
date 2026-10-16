<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\EcritureComptable;
use App\Models\ExerciceComptable;
use App\Models\PeriodeComptable;
use App\Services\Comptabilite\Clotures;
use App\Services\Comptabilite\Exercices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Exercices comptables et leurs périodes : création avec découpage automatique, modification tant qu'aucune
 * période n'est fermée, fermeture et réouverture des périodes. Consultation avec le module Finance ;
 * modifications par les administrateurs et responsables, réouverture par les administrateurs (routes).
 */
class ExerciceComptableController extends Controller
{
    public function __construct(private Exercices $exercices) {}

    public function index(): JsonResponse
    {
        $courante = $this->exercices->periodeDu();
        $exercices = ExerciceComptable::with(['periodes.fermeePar:id,nom', 'cloturePar:id,nom'])->orderByDesc('date_debut')->get();

        return response()->json([
            'exercices' => $exercices->map(fn (ExerciceComptable $e) => $this->presenter($e, $courante)),
            'periode_courante_id' => $courante?->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request);
        [$debut, $fin] = [Carbon::parse($donnees['date_debut'])->startOfDay(), Carbon::parse($donnees['date_fin'])->startOfDay()];
        if ($erreur = $this->exercices->controlerDates($debut, $fin)) {
            return response()->json(['message' => $erreur, 'errors' => ['date_fin' => [$erreur]]], 422);
        }

        $exercice = DB::transaction(function () use ($donnees, $debut, $fin) {
            $exercice = ExerciceComptable::create([
                'libelle' => trim((string) ($donnees['libelle'] ?? '')) ?: $this->exercices->libelleParDefaut($debut, $fin),
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin->toDateString(),
                'statut' => ExerciceComptable::OUVERT,
            ]);
            $this->exercices->genererPeriodes($exercice, $donnees['decoupage']);

            return $exercice;
        });

        return response()->json($this->presenter($exercice->load('periodes'), $this->exercices->periodeDu()), 201);
    }

    /** Libellé toujours ; dates et découpage tant qu'aucune période n'est fermée (les périodes sont recréées). */
    public function update(Request $request, int $id): JsonResponse
    {
        $exercice = ExerciceComptable::with('periodes')->findOrFail($id);
        if ($exercice->estCloture()) {
            return response()->json(['message' => 'Un exercice clôturé ne se modifie plus.'], 422);
        }
        $donnees = $this->valider($request);
        [$debut, $fin] = [Carbon::parse($donnees['date_debut'])->startOfDay(), Carbon::parse($donnees['date_fin'])->startOfDay()];
        $redecouper = ! $debut->equalTo($exercice->date_debut) || ! $fin->equalTo($exercice->date_fin)
            || $donnees['decoupage'] !== $this->exercices->decoupageDe($exercice);

        if ($redecouper) {
            if ($exercice->periodes->contains('fermee', true)) {
                return response()->json(['message' => 'Des périodes de cet exercice sont fermées : ses dates et son découpage ne peuvent plus changer.'], 422);
            }
            if (EcritureComptable::where('exercice_id', $exercice->id)->exists()) {
                return response()->json(['message' => 'Cet exercice a des écritures : ses dates et son découpage ne peuvent plus changer.'], 422);
            }
            if ($erreur = $this->exercices->controlerDates($debut, $fin, $exercice)) {
                return response()->json(['message' => $erreur, 'errors' => ['date_fin' => [$erreur]]], 422);
            }
        }

        DB::transaction(function () use ($exercice, $donnees, $debut, $fin, $redecouper) {
            $exercice->update([
                'libelle' => trim((string) ($donnees['libelle'] ?? '')) ?: $this->exercices->libelleParDefaut($debut, $fin),
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin->toDateString(),
            ]);
            if ($redecouper) {
                $this->exercices->genererPeriodes($exercice, $donnees['decoupage']);
            }
        });

        return response()->json($this->presenter($exercice->fresh('periodes'), $this->exercices->periodeDu()));
    }

    public function destroy(int $id): JsonResponse
    {
        $exercice = ExerciceComptable::findOrFail($id);
        if ($exercice->estCloture() || $exercice->periodes()->where('fermee', true)->exists()) {
            return response()->json(['message' => 'Un exercice clôturé, ou dont une période est fermée, ne peut pas être supprimé.'], 422);
        }
        if (EcritureComptable::where('exercice_id', $exercice->id)->exists()) {
            return response()->json(['message' => 'Cet exercice a des écritures : il ne peut pas être supprimé.'], 422);
        }
        DB::transaction(function () use ($exercice) {
            $exercice->periodes()->delete();
            $exercice->delete();
        });

        return response()->json(['message' => 'Exercice supprimé.']);
    }

    /** Ce que donnerait la clôture : produits, charges, résultat, compte qui le reçoit, et ce qui l'empêche. */
    public function apercuCloture(int $id, Clotures $clotures): JsonResponse
    {
        return response()->json($clotures->apercu(ExerciceComptable::findOrFail($id)));
    }

    public function cloturer(Request $request, int $id, Clotures $clotures): JsonResponse
    {
        $exercice = $clotures->cloturer(ExerciceComptable::findOrFail($id), $request->user()?->id);

        return response()->json($this->presenter($exercice->load('periodes.fermeePar:id,nom', 'cloturePar:id,nom'), $this->exercices->periodeDu()));
    }

    public function rouvrir(int $id, Clotures $clotures): JsonResponse
    {
        $exercice = $clotures->rouvrir(ExerciceComptable::findOrFail($id));

        return response()->json($this->presenter($exercice->load('periodes.fermeePar:id,nom'), $this->exercices->periodeDu()));
    }

    /** Une période fermée n'accepte plus d'écriture. */
    public function fermerPeriode(Request $request, int $id): JsonResponse
    {
        $periode = PeriodeComptable::findOrFail($id);
        if ($periode->fermee) {
            return response()->json(['message' => 'Cette période est déjà fermée.'], 422);
        }
        $periode->update(['fermee' => true, 'fermee_le' => now(), 'fermee_par' => $request->user()?->id]);

        return response()->json($this->presenterPeriode($periode->fresh('fermeePar:id,nom'), $this->exercices->periodeDu()));
    }

    public function rouvrirPeriode(int $id): JsonResponse
    {
        $periode = PeriodeComptable::with('exercice')->findOrFail($id);
        if (! $periode->fermee) {
            return response()->json(['message' => 'Cette période est déjà ouverte.'], 422);
        }
        if ($periode->exercice->estCloture()) {
            return response()->json(['message' => "L'exercice {$periode->exercice->libelle} est clôturé : sa période ne peut pas être rouverte."], 422);
        }
        $periode->update(['fermee' => false, 'fermee_le' => null, 'fermee_par' => null]);

        return response()->json($this->presenterPeriode($periode->fresh(), $this->exercices->periodeDu()));
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'libelle' => ['nullable', 'string', 'max:50'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date'],
            'decoupage' => ['required', Rule::in(Exercices::DECOUPAGES)],
        ], ['decoupage.in' => 'Découpage inconnu : mensuel, trimestriel ou unique.']);
    }

    private function presenter(ExerciceComptable $exercice, ?PeriodeComptable $courante): array
    {
        return [
            'id' => $exercice->id,
            'libelle' => $exercice->libelle,
            'date_debut' => $exercice->date_debut->toDateString(),
            'date_fin' => $exercice->date_fin->toDateString(),
            'statut' => $exercice->statut,
            'cloture_le' => $exercice->cloture_le?->toIso8601String(),
            'cloture_par' => $exercice->cloturePar?->nom,
            'decoupage' => $this->exercices->decoupageDe($exercice),
            'periodes' => $exercice->periodes->map(fn (PeriodeComptable $p) => $this->presenterPeriode($p, $courante))->values(),
        ];
    }

    private function presenterPeriode(PeriodeComptable $periode, ?PeriodeComptable $courante): array
    {
        return [
            'id' => $periode->id,
            'exercice_id' => $periode->exercice_id,
            'numero' => $periode->numero,
            'date_debut' => $periode->date_debut->toDateString(),
            'date_fin' => $periode->date_fin->toDateString(),
            'fermee' => $periode->fermee,
            'fermee_le' => $periode->fermee_le?->toIso8601String(),
            'fermee_par' => $periode->fermeePar?->nom,
            'courante' => $courante?->id === $periode->id,
        ];
    }
}
