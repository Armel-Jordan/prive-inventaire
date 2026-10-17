<?php

namespace App\Http\Controllers\Banque;

use App\Http\Controllers\Controller;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Services\Banque\RapprochementPdf;
use App\Services\Banque\Rapprochements;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Rapprochement bancaire : relevés d'un compte, pointage des mouvements, fin, réouverture, suppression, rapport. */
class RapprochementBancaireController extends Controller
{
    public function __construct(private readonly Rapprochements $rapprochements) {}

    /** Rapprochements d'un compte bancaire, le plus récent d'abord. */
    public function index(int $id): JsonResponse
    {
        return response()->json($this->rapprochements->liste(CompteBancaire::query()->findOrFail($id)));
    }

    /** Commence le rapprochement d'un relevé (date, solde de fin). */
    public function store(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $donnees = $request->validate([
            'date_releve' => 'required|date|before_or_equal:tomorrow',
            'solde_releve' => 'required|numeric|between:-9999999999999,9999999999999',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->rapprochements->commencer($compte, $donnees, $request->user()?->id), 201));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->rapprochements->detail(RapprochementBancaire::query()->findOrFail($id)));
    }

    /** Corrige le relevé d'un rapprochement en cours. */
    public function update(Request $request, int $id): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);
        $donnees = $request->validate([
            'date_releve' => 'sometimes|date|before_or_equal:tomorrow',
            'solde_releve' => 'sometimes|numeric|between:-9999999999999,9999999999999',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->rapprochements->detail($this->rapprochements->modifier($rapprochement, $donnees))));
    }

    /** Pointe ou retire des mouvements. */
    public function pointer(Request $request, int $id): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);
        $donnees = $request->validate([
            'lignes' => 'required|array|min:1|max:1000',
            'lignes.*' => 'integer',
            'pointee' => 'required|boolean',
        ]);

        return $this->executer(fn () => response()->json($this->rapprochements->pointer($rapprochement, array_map('intval', $donnees['lignes']), (bool) $donnees['pointee'])));
    }

    public function terminer(Request $request, int $id): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);

        return $this->executer(fn () => response()->json($this->rapprochements->terminer($rapprochement, $request->user()?->id)));
    }

    public function rouvrir(int $id): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);

        return $this->executer(fn () => response()->json($this->rapprochements->rouvrir($rapprochement)));
    }

    public function destroy(int $id): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);

        return $this->executer(function () use ($rapprochement) {
            $this->rapprochements->supprimer($rapprochement);

            return response()->json(['message' => 'Rapprochement supprimé : ses mouvements sont libérés.']);
        });
    }

    /** Rapport du rapprochement : mouvements pointés et en circulation, preuve du solde. */
    public function pdf(int $id, RapprochementPdf $pdf): Response
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);

        return $pdf->pdf($rapprochement)->stream("rapprochement-{$rapprochement->date_releve->toDateString()}.pdf");
    }

    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
