<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompteBancaire;
use App\Models\VirementLot;
use App\Services\Virements;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Virements aux fournisseurs : déboursés à transmettre, lots (fichier SEPA ou CSV), téléchargement, refus de la banque. */
class VirementController extends Controller
{
    public function __construct(private readonly Virements $virements) {}

    /** Déboursés payés par virement selon leur état, et les comptes de l'entreprise d'où ils partent. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'statut' => ['nullable', Rule::in(Virements::STATUTS)],
            'compte_bancaire_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'debourses' => $this->virements->liste($filtres['statut'] ?? 'a_transmettre', isset($filtres['compte_bancaire_id']) ? (int) $filtres['compte_bancaire_id'] : null),
            'comptes' => $this->virements->comptesEntreprise(),
        ]);
    }

    /** Lots transmis, les plus récents d'abord. */
    public function lots(Request $request): JsonResponse
    {
        $filtres = $request->validate(['compte_bancaire_id' => ['nullable', 'integer']]);

        return response()->json(VirementLot::query()->with(['compteBancaire:id,nom,banque', 'auteur:id,nom', 'auteurRefus:id,nom'])
            ->when($filtres['compte_bancaire_id'] ?? null, fn ($q, $id) => $q->where('compte_bancaire_id', $id))
            ->orderByDesc('id')->limit(200)->get());
    }

    /** Crée un lot et son fichier pour des déboursés d'un même compte bancaire et d'une même devise. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'compte_bancaire_id' => ['required', 'integer'],
            'debourses' => ['required', 'array', 'min:1', 'max:500'],
            'debourses.*' => ['integer'],
            'format' => ['required', Rule::in(Virements::FORMATS)],
            'date_execution' => ['required', 'date', 'after_or_equal:today'],
            'numero' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'emetteur' => ['nullable', 'string', 'max:35'],
        ], ['date_execution.after_or_equal' => "La date d'exécution ne peut pas être passée."]);
        $compte = CompteBancaire::query()->findOrFail($donnees['compte_bancaire_id']);
        $lot = $this->virements->generer($compte, array_map('intval', $donnees['debourses']), $donnees['format'], $donnees['date_execution'],
            isset($donnees['numero']) ? (int) $donnees['numero'] : null, $donnees['emetteur'] ?? null, $request->user()?->id);

        return response()->json($lot->load(['compteBancaire:id,nom,banque', 'auteur:id,nom']), 201);
    }

    /** Le fichier du lot, tel qu'il a été transmis. */
    public function fichier(int $id): Response
    {
        $lot = VirementLot::query()->findOrFail($id);

        return response($lot->contenu, 200, [
            'Content-Type' => $lot->format === 'sepa' ? 'application/xml; charset=UTF-8' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$lot->fichier.'"',
        ]);
    }

    /** Lot refusé par la banque : ses déboursés redeviennent à transmettre. */
    public function refuser(Request $request, int $id): JsonResponse
    {
        $lot = VirementLot::query()->findOrFail($id);
        $donnees = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);
        try {
            return response()->json($this->virements->refuser($lot, $donnees['motif'] ?? null, $request->user()?->id));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
