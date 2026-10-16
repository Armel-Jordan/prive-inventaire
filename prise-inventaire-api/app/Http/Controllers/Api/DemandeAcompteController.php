<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemandeAcompte;
use App\Services\Acomptes;
use App\Services\DemandesAcompte;
use App\Services\DevisesVente;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demandes d'acompte : liste, détail, saisie (brouillon), envoi, paiement (crée l'acompte) et suppression. Les
 * règles sont dans App\Services\DemandesAcompte.
 */
class DemandeAcompteController extends Controller
{
    public function __construct(private readonly DemandesAcompte $demandes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => ['nullable', Rule::in([DemandeAcompte::BROUILLON, DemandeAcompte::ENVOYEE, DemandeAcompte::PAYEE])],
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d',
        ]);
        $recherche = trim((string) $request->get('recherche', ''));

        $demandes = DemandeAcompte::with(['client:id,code,raison_sociale', 'devis:id,numero', 'commande:id,numero', 'acompte:id,montant,montant_applique,montant_rembourse'])
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date_demande', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date_demande', '<=', $request->date_fin))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)->orWhere('description', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
            })
            ->orderByDesc('date_demande')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json($demandes);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(DemandeAcompte::with([
            'client:id,code,raison_sociale,email', 'devis:id,numero', 'commande:id,numero',
            'acompte.applications.facture:id,numero',
        ])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request, true);

        return $this->executer(fn () => response()->json(
            $this->demandes->creer($donnees, $request->attributes->get('tenant')->id, $request->user()?->id)->load('client:id,code,raison_sociale'), 201,
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $demande = DemandeAcompte::findOrFail($id);
        $donnees = $this->valider($request, false);

        return $this->executer(fn () => response()->json($this->demandes->modifier($demande, $donnees)->load('client:id,code,raison_sociale')));
    }

    public function destroy(int $id): JsonResponse
    {
        $demande = DemandeAcompte::findOrFail($id);

        return $this->executer(function () use ($demande) {
            $this->demandes->supprimer($demande);

            return response()->json(['message' => "Demande d'acompte supprimée."]);
        });
    }

    public function envoyer(int $id): JsonResponse
    {
        return response()->json($this->demandes->envoyer(DemandeAcompte::findOrFail($id)));
    }

    /** Dépôt reçu : la demande passe payée et l'acompte est enregistré au dossier du client. */
    public function encaisser(Request $request, int $id): JsonResponse
    {
        $demande = DemandeAcompte::findOrFail($id);
        $paiement = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'mode' => ['required', Rule::in(Acomptes::MODES)],
            'reference' => 'nullable|string|max:100',
        ]);

        return $this->executer(fn () => response()->json($this->demandes->encaisser($demande, $paiement, $request->user()?->id)->load('acompte')));
    }

    private function valider(Request $request, bool $creation): array
    {
        $requis = $creation ? 'required' : 'sometimes';

        return $request->validate([
            'client_id' => [$requis, 'integer', TenantRule::exists('clients')],
            'date_demande' => "{$requis}|date_format:Y-m-d",
            'description' => "{$requis}|string|max:5000",
            'montant' => "{$requis}|numeric|min:0.01|max:9999999999",
            'devis_id' => 'sometimes|nullable|integer',
            'commande_id' => 'sometimes|nullable|integer',
            ...($creation ? DevisesVente::regles() : []),
        ]);
    }

    /** Erreurs de règle (état de la demande) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
