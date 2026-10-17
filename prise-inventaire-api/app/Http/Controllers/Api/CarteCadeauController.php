<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarteCadeau;
use App\Services\CartesCadeaux;
use App\Services\CertificatCadeauPdf;
use App\Support\Droits;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cartes cadeaux : liste (recherche, état, solde à honorer), fiche et mouvements, vente, modification (client,
 * expiration, notes), recharge, remboursement du solde (la carte est annulée) et certificat. Les règles sont dans
 * App\Services\CartesCadeaux.
 */
class CarteCadeauController extends Controller
{
    public const ETATS = ['active', 'epuisee', 'expiree', 'annulee'];

    public function __construct(private readonly CartesCadeaux $cartes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['etat' => ['nullable', Rule::in(self::ETATS)]]);
        $recherche = trim((string) $request->get('recherche', ''));
        $valable = fn ($q) => $q->where('annulee', false)->where(fn ($r) => $r->whereNull('expire_le')->orWhereDate('expire_le', '>=', today()));

        $requete = CarteCadeau::query()
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
            })
            ->when($request->get('etat') === 'annulee', fn ($q) => $q->where('annulee', true))
            ->when($request->get('etat') === 'expiree', fn ($q) => $q->where('annulee', false)->whereNotNull('expire_le')->whereDate('expire_le', '<', today()))
            ->when($request->get('etat') === 'epuisee', fn ($q) => $valable($q)->where('solde', '<=', 0.004))
            ->when($request->get('etat') === 'active', fn ($q) => $valable($q)->where('solde', '>', 0.004));
        // Solde à honorer : ce que les cartes valables peuvent encore payer, dans la devise de l'entreprise.
        $aHonorer = $valable((clone $requete)->toBase())->whereNull('devise')->sum('solde');
        $page = $requete->with('client:id,code,raison_sociale')->withCount('mouvements')
            ->orderByDesc('date_vente')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json($page->toArray() + ['totaux' => ['a_honorer' => round((float) $aHonorer, 2)]]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(CarteCadeau::with(['client:id,code,raison_sociale', 'mouvements.facture:id,numero', 'mouvements.auteur:id,nom'])->findOrFail($id));
    }

    /** Vente d'une carte : montant encaissé par son mode, sur la caisse du poste s'il en a une. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            // Carte pré-imprimée (numérotation manuelle) : son numéro.
            'numero' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'montant' => 'required|numeric|min:0.01|max:9999999999',
            'mode' => ['required', Rule::in(CartesCadeaux::MODES)],
            'reference' => 'nullable|string|max:100',
            // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
            'date' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'expire_le' => 'nullable|date_format:Y-m-d|after_or_equal:date',
            'notes' => 'nullable|string|max:1000',
            'caisse_id' => 'nullable|integer',
        ], [
            'numero.regex' => 'Le numéro ne contient que des lettres, des chiffres et des tirets.',
            'expire_le.after_or_equal' => "L'expiration ne peut pas précéder la vente.",
        ]);

        return $this->executer(fn () => response()->json(
            $this->cartes->vendre($donnees, $request->user()?->id, $request->user()->tenant_id)->load('client:id,code,raison_sociale'), 201,
        ));
    }

    /** Client acheteur, expiration et notes ; le numéro, la devise et le solde ne changent pas. */
    public function update(Request $request, int $id): JsonResponse
    {
        $carte = CarteCadeau::findOrFail($id);
        $donnees = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('clients')],
            'expire_le' => 'sometimes|nullable|date_format:Y-m-d',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($carte->annulee) {
            return response()->json(['message' => 'Une carte annulée ne se modifie plus.'], 422);
        }
        if (! empty($donnees['expire_le']) && $donnees['expire_le'] < $carte->date_vente->toDateString()) {
            return response()->json(['message' => "L'expiration ne peut pas précéder la vente.", 'errors' => ['expire_le' => ["L'expiration ne peut pas précéder la vente."]]], 422);
        }
        $carte->update($donnees);

        return response()->json($carte->fresh('client:id,code,raison_sociale'));
    }

    public function recharger(Request $request, int $id): JsonResponse
    {
        $carte = CarteCadeau::findOrFail($id);
        $donnees = $request->validate([
            'montant' => 'required|numeric|min:0.01|max:9999999999',
            'mode' => ['required', Rule::in(CartesCadeaux::MODES)],
            'reference' => 'nullable|string|max:100',
            'caisse_id' => 'nullable|integer',
        ]);

        return $this->executer(fn () => response()->json($this->cartes->recharger(
            $carte, (float) $donnees['montant'], $donnees['mode'], $donnees['reference'] ?? null, $donnees['caisse_id'] ?? null, $request->user()?->id,
        )));
    }

    /** Rend tout le solde et annule la carte : il faut aussi le droit de modifier les cartes. */
    public function rembourser(Request $request, int $id, Droits $droits): JsonResponse
    {
        $carte = CarteCadeau::findOrFail($id);
        $donnees = $request->validate([
            'mode' => ['required', Rule::in(CartesCadeaux::MODES)],
            'reference' => 'nullable|string|max:100',
            'caisse_id' => 'nullable|integer',
        ]);
        if (! $droits->permet($request->user(), 'cartes_cadeaux', 'modifier')) {
            return response()->json(['message' => $droits->messageRefus('cartes_cadeaux', 'modifier'), 'module' => 'cartes_cadeaux', 'action' => 'modifier'], 403);
        }

        return $this->executer(fn () => response()->json($this->cartes->rembourser(
            $carte, $donnees['mode'], $donnees['reference'] ?? null, $donnees['caisse_id'] ?? null, $request->user()?->id,
        )));
    }

    /** Certificat cadeau à remettre au client (le solde est celui du moment). */
    public function certificat(int $id, CertificatCadeauPdf $documents): Response
    {
        $carte = CarteCadeau::findOrFail($id);

        return $documents->certificat($carte)->stream("certificat-{$carte->numero}.pdf");
    }

    /** Erreurs de règle (état de la carte) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
