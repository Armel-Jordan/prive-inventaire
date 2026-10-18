<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\JournalComptable;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Journaux comptables (opérations diverses, ventes, achats, banque, caisse, à-nouveaux), créés au premier accès.
 * Le code préfixe le numéro des écritures : il ne change plus une fois des écritures passées.
 */
class JournalComptableController extends Controller
{
    public function index(): JsonResponse
    {
        JournalComptable::assurerParDefaut();

        return response()->json(JournalComptable::withCount('ecritures')->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        JournalComptable::assurerParDefaut();
        $donnees = $this->valider($request);

        return response()->json(JournalComptable::create($donnees + ['actif' => true]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $journal = JournalComptable::findOrFail($id);
        $donnees = $this->valider($request, $journal);
        if ($donnees['code'] !== $journal->code && $journal->ecritures()->exists()) {
            return response()->json([
                'message' => __('Ce journal a des écritures : son code, qui préfixe leurs numéros, ne peut plus changer.'),
                'errors' => ['code' => [__('Ce journal a des écritures : son code ne peut plus changer.')]],
            ], 422);
        }
        $journal->update($donnees);

        return response()->json($journal->loadCount('ecritures'));
    }

    public function destroy(int $id): JsonResponse
    {
        $journal = JournalComptable::findOrFail($id);
        if ($journal->ecritures()->exists()) {
            return response()->json(['message' => __('Ce journal a des écritures : désactivez-le plutôt.')], 422);
        }
        $journal->delete();

        return response()->json(['message' => __('Journal supprimé.')]);
    }

    private function valider(Request $request, ?JournalComptable $journal = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', TenantRule::unique('journaux_comptables', 'code', $journal?->id)],
            'libelle' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(JournalComptable::TYPES)],
            'actif' => ['sometimes', 'boolean'],
        ], [
            'code.unique' => __('Ce code de journal existe déjà.'),
            'code.regex' => __('Le code ne contient que des lettres et des chiffres.'),
        ]);
    }
}
