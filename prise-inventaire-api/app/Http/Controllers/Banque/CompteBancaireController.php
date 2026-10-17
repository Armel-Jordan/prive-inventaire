<?php

namespace App\Http\Controllers\Banque;

use App\Http\Controllers\Controller;
use App\Models\CompteBancaire;
use App\Services\Banque\ComptesBancaires;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Comptes bancaires côté comptabilité : liste avec soldes, compte comptable et journal, mouvements (registre). */
class CompteBancaireController extends Controller
{
    public function __construct(private readonly ComptesBancaires $comptes) {}

    public function index(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => 'nullable|date'])['date'] ?? today()->toDateString();

        return response()->json(['date' => Carbon::parse($date)->toDateString(), 'comptes' => $this->comptes->liste(Carbon::parse($date)->toDateString())]);
    }

    /** Relie le compte bancaire à son compte comptable et à son journal. */
    public function update(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $donnees = $request->validate([
            'compte_comptable_id' => 'nullable|integer',
            'journal_id' => 'nullable|integer',
            'prochain_cheque' => 'nullable|integer|min:1|max:999999999999',
        ]);

        return response()->json($this->comptes->configurer($compte, $donnees));
    }

    /** Mouvements du compte bancaire (son compte comptable) entre deux dates, avec le solde progressif. */
    public function mouvements(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $dates = $request->validate([
            'du' => 'nullable|date',
            'au' => 'nullable|date|after_or_equal:du',
            'recherche' => 'nullable|string|max:100',
        ]);
        $au = $dates['au'] ?? today()->toDateString();
        $du = $dates['du'] ?? Carbon::parse($au)->startOfMonth()->toDateString();

        try {
            return response()->json($this->comptes->mouvements($compte, $du, $au, $dates['recherche'] ?? null));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
