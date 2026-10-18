<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupeTaxe;
use App\Models\GroupeTaxeLigne;
use App\Services\CalculTaxes;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Groupes de taxes du tenant (combinaisons ordonnées de tenant_taxes) et leur calcul. */
class GroupeTaxeController extends Controller
{
    public function __construct(private readonly CalculTaxes $calcul) {}

    public function index(): JsonResponse
    {
        $groupes = GroupeTaxe::with('lignes.taxe:id,nom,taux')->orderBy('code')->get()
            ->each(fn (GroupeTaxe $g) => $g->setAttribute('taux_effectif', $this->calcul->tauxEffectif($g)));

        return response()->json($groupes);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request, null);
        $groupe = DB::transaction(fn () => $this->enregistrer(new GroupeTaxe, $donnees));

        return response()->json($this->presenter($groupe), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $groupe = GroupeTaxe::findOrFail($id);
        $donnees = $this->valider($request, $groupe->id);
        DB::transaction(fn () => $this->enregistrer($groupe, $donnees));

        return response()->json($this->presenter($groupe));
    }

    public function destroy(int $id): JsonResponse
    {
        GroupeTaxe::findOrFail($id)->delete();

        return response()->json(['message' => __('Groupe de taxes supprimé.')]);
    }

    /** Détail des taxes du groupe sur un montant HT. */
    public function calculer(Request $request, int $id): JsonResponse
    {
        $montant = $request->validate(['montant_ht' => ['required', 'numeric', 'min:0']])['montant_ht'];

        return response()->json($this->calcul->calculer(GroupeTaxe::findOrFail($id), (float) $montant));
    }

    private function valider(Request $request, ?int $id): array
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:20', TenantRule::unique('groupes_taxes', 'code', $id)],
            'nom' => ['required', 'string', 'max:100'],
            'par_defaut' => ['boolean'],
            'actif' => ['boolean'],
            // Liste vide autorisée : groupe « exonéré ».
            'lignes' => ['present', 'array', 'max:5'],
            'lignes.*.tenant_taxe_id' => ['required', 'integer', 'distinct', TenantRule::exists('tenant_taxes')],
            'lignes.*.applique_sur' => ['required', Rule::in(GroupeTaxeLigne::MODES)],
        ]);

        if (($donnees['lignes'][0]['applique_sur'] ?? null) === 'taxe_precedente') {
            throw ValidationException::withMessages([
                'lignes.0.applique_sur' => __("La première taxe ne peut pas s'appliquer sur une taxe précédente."),
            ]);
        }

        return $donnees;
    }

    private function enregistrer(GroupeTaxe $groupe, array $donnees): GroupeTaxe
    {
        $groupe->fill(collect($donnees)->except('lignes')->all())->save();

        $groupe->lignes()->delete();
        foreach ($donnees['lignes'] as $ordre => $ligne) {
            $groupe->lignes()->create($ligne + ['ordre' => $ordre]);
        }

        if ($groupe->par_defaut) {
            GroupeTaxe::whereKeyNot($groupe->id)->where('par_defaut', true)->update(['par_defaut' => false]);
        }

        return $groupe;
    }

    private function presenter(GroupeTaxe $groupe): GroupeTaxe
    {
        $groupe = $groupe->fresh('lignes.taxe:id,nom,taux');

        return $groupe->setAttribute('taux_effectif', $this->calcul->tauxEffectif($groupe));
    }
}
