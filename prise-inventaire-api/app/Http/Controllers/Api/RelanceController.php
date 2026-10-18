<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\FactureRelance;
use App\Services\Relances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Relances de paiement : factures en retard avec leurs relances, historique, relance notée à la main. Les relances
 * par courriel passent par l'envoi des courriels (type « relance ») et sont notées à leur envoi.
 */
class RelanceController extends Controller
{
    public function __construct(private readonly Relances $relances) {}

    /** Factures en retard à une date (aujourd'hui par défaut), avec leurs relances. */
    public function index(Request $request): JsonResponse
    {
        $parametres = $request->validate([
            'date' => 'nullable|date',
            'retard_min' => 'nullable|integer|min:0',
            'client_id' => 'nullable|integer',
            'jamais' => 'nullable|boolean',
            'recherche' => 'nullable|string|max:100',
        ]);
        $date = Carbon::parse($parametres['date'] ?? today())->toDateString();
        $factures = $this->relances->enRetard($date, [
            'client_id' => $parametres['client_id'] ?? null,
            'retard_min' => $parametres['retard_min'] ?? null,
            'jamais' => $request->boolean('jamais'),
            'recherche' => $parametres['recherche'] ?? null,
        ]);

        return response()->json([
            'date' => $date,
            'factures' => $factures->all(),
            'totaux' => [
                'factures' => $factures->count(),
                'clients' => $factures->pluck('client.id')->unique()->count(),
                // Dans la devise de l'entreprise.
                'reste_a_payer' => round($factures->sum('reste_reference'), 2),
                'jamais_relancees' => $factures->where('nb_relances', 0)->count(),
            ],
        ]);
    }

    /** Historique des relances, le plus récent d'abord ; filtrable par client ou par facture. */
    public function historique(Request $request): JsonResponse
    {
        return response()->json(FactureRelance::with(['facture:id,numero', 'client:id,code,raison_sociale', 'auteur:id,nom'])
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('facture_id'), fn ($q) => $q->where('facture_id', $request->integer('facture_id')))
            ->orderByDesc('date_relance')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20)))));
    }

    /** Relances d'une facture, dans l'ordre. */
    public function deFacture(int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        return response()->json(FactureRelance::with('auteur:id,nom')->where('facture_id', $facture->id)->orderBy('niveau')->get());
    }

    /** Note une relance faite autrement que par courriel depuis l'application (téléphone, courrier…). */
    public function store(Request $request, int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);
        // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
        $donnees = $request->validate([
            'mode' => ['required', Rule::in(FactureRelance::MODES)],
            'date' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'notes' => 'nullable|string|max:2000',
        ]);
        if (in_array($facture->statut, ['brouillon', 'annulee'], true)) {
            return response()->json(['message' => __('Seule une facture émise se relance.')], 422);
        }

        return response()->json($this->relances->noter($facture, $donnees['mode'], $donnees['date'], $donnees['notes'] ?? null, $request->user()?->id), 201);
    }
}
