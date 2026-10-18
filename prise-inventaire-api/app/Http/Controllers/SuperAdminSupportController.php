<?php

namespace App\Http\Controllers;

use App\Models\DemandeSupport;
use App\Models\DemandeSupportMessage;
use App\Services\Support\DemandesSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demandes de support et suggestions, côté éditeur (super-administrateur ; plan de parité fripv2, phase 6.7) : toutes
 * les demandes des entreprises, filtrées ; réponse, statut et priorité.
 */
class SuperAdminSupportController extends Controller
{
    public function __construct(private readonly DemandesSupport $demandes) {}

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'statut' => ['nullable', Rule::in(DemandeSupport::STATUTS)],
            'type' => ['nullable', Rule::in(DemandeSupport::TYPES)],
            'tenant_id' => ['nullable', 'integer'],
            // « actives » : ni résolues ni fermées.
            'actives' => ['nullable', 'boolean'],
        ]);

        $demandes = DemandeSupport::query()->with(['auteur:id,nom,email', 'entreprise:id,nom'])
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filtres['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($filtres['actives'] ?? false, fn ($q) => $q->whereNotIn('statut', ['resolue', 'fermee']))
            ->orderByDesc('dernier_message_le')->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'demandes' => $demandes->map(fn (DemandeSupport $d) => $this->resume($d))->values()->all(),
            // Nombre de demandes par statut, toutes entreprises confondues.
            'par_statut' => DemandeSupport::query()->selectRaw('statut, COUNT(*) AS nombre')->groupBy('statut')->pluck('nombre', 'statut')
                ->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->detail(DemandeSupport::with(['auteur:id,nom,email', 'entreprise:id,nom'])->findOrFail($id)));
    }

    public function repondre(Request $request, int $id): JsonResponse
    {
        $demande = DemandeSupport::findOrFail($id);
        $donnees = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            // Statut après la réponse (en attente de l'usager par défaut).
            'statut' => ['nullable', Rule::in(DemandeSupport::STATUTS)],
        ]);
        $this->demandes->repondreEditeur($demande, $request->user(), $donnees['message'], $donnees['statut'] ?? null);

        return response()->json($this->detail($demande->refresh()->load(['auteur:id,nom,email', 'entreprise:id,nom'])), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $demande = DemandeSupport::findOrFail($id);
        $donnees = $request->validate([
            'statut' => ['sometimes', Rule::in(DemandeSupport::STATUTS)],
            'priorite' => ['sometimes', Rule::in(DemandeSupport::PRIORITES)],
        ]);
        if (isset($donnees['priorite'])) {
            $demande->update(['priorite' => $donnees['priorite']]);
        }
        if (isset($donnees['statut']) && $donnees['statut'] !== $demande->statut) {
            $this->demandes->changerStatut($demande, $donnees['statut'], [], true);
        }

        return response()->json($this->detail($demande->refresh()->load(['auteur:id,nom,email', 'entreprise:id,nom'])));
    }

    private function resume(DemandeSupport $demande): array
    {
        return $demande->only(['id', 'tenant_id', 'type', 'sujet', 'priorite', 'statut', 'ecran', 'dernier_auteur']) + [
            'entreprise' => $demande->entreprise?->nom,
            'auteur' => $demande->auteur?->nom,
            'auteur_email' => $demande->auteur?->email,
            'dernier_message_le' => $demande->dernier_message_le?->toIso8601String(),
            'resolue_le' => $demande->resolue_le?->toIso8601String(),
            'created_at' => $demande->created_at?->toIso8601String(),
        ];
    }

    private function detail(DemandeSupport $demande): array
    {
        return $this->resume($demande) + [
            'description' => $demande->description,
            'messages' => DemandeSupportMessage::query()->where('demande_support_id', $demande->id)->with(['compte:id,nom', 'superAdmin:id,nom'])->orderBy('id')->get()
                ->map(fn (DemandeSupportMessage $m) => [
                    'id' => $m->id,
                    'auteur' => $m->auteur,
                    'nom' => $m->auteur === 'editeur' ? $m->superAdmin?->nom : $m->compte?->nom,
                    'message' => $m->message,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
