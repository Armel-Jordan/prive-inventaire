<?php

namespace App\Http\Controllers;

use App\Models\DemandeSupport;
use App\Models\DemandeSupportMessage;
use App\Services\Support\DemandesSupport;
use App\Support\Droits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demandes de support et suggestions, côté entreprise (plan de parité fripv2, phase 6.7) : écrire à l'éditeur, suivre
 * ses demandes et y répondre. Chacun voit les siennes ; avec le droit de voir le support, toutes celles de
 * l'entreprise.
 */
class SupportController extends Controller
{
    public function __construct(private readonly DemandesSupport $demandes, private readonly Droits $droits) {}

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'statut' => ['nullable', Rule::in(DemandeSupport::STATUTS)],
            'type' => ['nullable', Rule::in(DemandeSupport::TYPES)],
        ]);
        $toutes = $this->droits->permet($request->user(), 'support', 'voir');

        return response()->json([
            'toutes' => $toutes,
            'demandes' => DemandeSupport::query()->with('auteur:id,nom')
                ->when(! $toutes, fn ($q) => $q->where('admin_user_id', $request->user()->id))
                ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
                ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->orderByDesc('dernier_message_le')->orderByDesc('id')->limit(200)->get()
                ->map(fn (DemandeSupport $d) => $this->resume($d))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(DemandeSupport::TYPES)],
            'sujet' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'priorite' => ['nullable', Rule::in(DemandeSupport::PRIORITES)],
            'ecran' => ['nullable', 'string', 'max:255'],
        ]);

        $demande = $this->demandes->creer($request->user(), $donnees);

        return response()->json($this->detail($demande->load('auteur:id,nom')), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->detail($this->demande($request, $id)));
    }

    public function repondre(Request $request, int $id): JsonResponse
    {
        $demande = $this->demande($request, $id);
        $donnees = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $this->demandes->repondreUsager($demande, $request->user(), $donnees['message']);

        return response()->json($this->detail($demande->refresh()->load('auteur:id,nom')), 201);
    }

    public function fermer(Request $request, int $id): JsonResponse
    {
        $demande = $this->demande($request, $id);
        $this->demandes->changerStatut($demande, 'fermee');

        return response()->json($this->detail($demande->refresh()->load('auteur:id,nom')));
    }

    /** Une demande que le compte peut ouvrir (la sienne, ou toutes avec le droit de voir le support) ; sinon introuvable. */
    private function demande(Request $request, int $id): DemandeSupport
    {
        $demande = DemandeSupport::with('auteur:id,nom')->findOrFail($id);
        abort_unless((int) $demande->admin_user_id === (int) $request->user()->id || $this->droits->permet($request->user(), 'support', 'voir'), 404, __('Demande introuvable.'));

        return $demande;
    }

    private function resume(DemandeSupport $demande): array
    {
        return $demande->only(['id', 'type', 'sujet', 'priorite', 'statut', 'ecran', 'dernier_auteur']) + [
            'auteur' => $demande->auteur?->nom,
            'dernier_message_le' => $demande->dernier_message_le?->toIso8601String(),
            'resolue_le' => $demande->resolue_le?->toIso8601String(),
            'created_at' => $demande->created_at?->toIso8601String(),
        ];
    }

    private function detail(DemandeSupport $demande): array
    {
        return $this->resume($demande) + [
            'description' => $demande->description,
            'messages' => $demande->messages()->with(['compte:id,nom', 'superAdmin:id,nom'])->get()->map(fn (DemandeSupportMessage $m) => [
                'id' => $m->id,
                'auteur' => $m->auteur,
                'nom' => $m->auteur === 'editeur' ? $m->superAdmin?->nom : $m->compte?->nom,
                'message' => $m->message,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
