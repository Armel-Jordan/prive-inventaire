<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Services\DevisesVente;
use App\Services\FacturesFournisseurs;
use App\Services\FraisTransportAchat;
use App\Services\TaxesDocument;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Factures fournisseurs (comptes à payer) : saisie, depuis des réceptions, rapprochement, paiements, annulation. */
class FactureFournisseurController extends Controller
{
    public function __construct(private readonly FacturesFournisseurs $factures, private readonly TaxesDocument $taxes) {}

    /** Liste filtrable (recherche, fournisseur, état de paiement, période), avec le reste à payer et le montant en retard. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => 'nullable|string|max:100',
            'fournisseur_id' => 'nullable|integer',
            'etat' => ['nullable', Rule::in([...FactureFournisseur::ETATS, 'a_payer'])],
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $recherche = trim($filtres['recherche'] ?? '');
        $requete = FactureFournisseur::query()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$recherche}%")
                ->orWhere('numero_fournisseur', 'like', "%{$recherche}%")
                ->orWhereHas('fournisseur', fn ($f) => $f->where('raison_sociale', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))))
            ->when($filtres['fournisseur_id'] ?? null, fn ($q, $id) => $q->where('fournisseur_id', $id))
            ->when($filtres['date_debut'] ?? null, fn ($q, $date) => $q->whereDate('date_facture', '>=', $date))
            ->when($filtres['date_fin'] ?? null, fn ($q, $date) => $q->whereDate('date_facture', '<=', $date));
        $resume = $this->factures->resume(clone $requete);
        $page = (clone $requete)->when($filtres['etat'] ?? null, fn ($q, $etat) => $q->etat($etat))
            ->with(['fournisseur:id,code,raison_sociale'])->withCount('receptions')
            ->orderByDesc('date_facture')->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    /** Facture proposée depuis des réceptions d'un même fournisseur pas encore facturées. */
    public function proposition(Request $request): JsonResponse
    {
        $donnees = $request->validate(['receptions' => 'required|array|min:1|max:50', 'receptions.*' => 'integer']);

        return response()->json($this->factures->proposition(array_map('intval', $donnees['receptions'])));
    }

    /**
     * Frais de transport proposé sur une facture saisie à la main pour ce fournisseur (phase 1.11b) : la ligne, ou null.
     */
    public function fraisTransport(Request $request, FraisTransportAchat $transport): JsonResponse
    {
        $donnees = $request->validate([
            'fournisseur_id' => ['required', 'integer', TenantRule::exists('fournisseurs')],
            'devise' => ['nullable', 'string', 'max:10'],
        ]);
        $fournisseur = Fournisseur::findOrFail($donnees['fournisseur_id']);
        $devise = strtoupper(trim((string) ($donnees['devise'] ?? '')));
        $devise = $devise === '' || $devise === app(DevisesVente::class)->reference() ? null : $devise;

        return response()->json(['ligne' => $transport->ligne($fournisseur, null, $devise)]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->regles());

        return $this->executer(fn () => response()->json($this->presenter(
            $this->factures->enregistrer($donnees, null, $request->attributes->get('tenant')->id, $request->user()),
        ), 201));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->presenter(FactureFournisseur::query()->with($this->factures->relations())->findOrFail($id)));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $facture = FactureFournisseur::findOrFail($id);
        $donnees = $request->validate($this->regles());

        return $this->executer(fn () => response()->json($this->presenter(
            $this->factures->enregistrer($donnees, $facture, $facture->tenant_id, $request->user()),
        )));
    }

    /** Aligne le coût réel des réceptions sur le prix facturé. */
    public function alignerCouts(int $id): JsonResponse
    {
        $facture = FactureFournisseur::findOrFail($id);

        return $this->executer(function () use ($facture) {
            $alignees = $this->factures->alignerCouts($facture);

            return response()->json([
                'message' => $alignees > 0 ? __(':nombre coût(s) de réception aligné(s) sur la facture.', ['nombre' => $alignees]) : __('Les coûts des réceptions correspondent déjà à la facture.'),
                'facture' => $this->presenter($facture->fresh($this->factures->relations())),
            ]);
        });
    }

    public function payer(Request $request, int $id): JsonResponse
    {
        $facture = FactureFournisseur::findOrFail($id);
        $donnees = $request->validate([
            'date_paiement' => 'required|date|before_or_equal:tomorrow',
            'montant' => 'required|numeric|gt:0',
            'mode' => ['required', Rule::in(FactureFournisseur::MODES)],
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->executer(fn () => response()->json($this->presenter($this->factures->payer($facture, $donnees, $request->user()))));
    }

    public function annulerPaiement(int $id, int $paiement): JsonResponse
    {
        $facture = FactureFournisseur::findOrFail($id);

        return $this->executer(fn () => response()->json($this->presenter($this->factures->annulerPaiement($facture, $paiement))));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $facture = FactureFournisseur::findOrFail($id);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->presenter(
            $this->factures->annuler($facture, $donnees['motif'] ?? null, $request->user()),
        )));
    }

    private function regles(): array
    {
        return [
            'fournisseur_id' => ['required', TenantRule::exists('fournisseurs')],
            'numero_fournisseur' => 'nullable|string|max:60',
            'date_facture' => 'required|date|before_or_equal:tomorrow',
            'date_echeance' => 'nullable|date|after_or_equal:date_facture',
            'condition_paiement_id' => ['nullable', TenantRule::exists('conditions_paiement')],
            'devise' => 'nullable|string|max:10',
            'taux_change' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:2000',
            'receptions' => 'nullable|array|max:50',
            'receptions.*' => 'integer',
            'lignes' => 'required|array|min:1|max:500',
            'lignes.*.produit_id' => ['nullable', TenantRule::exists('produits')],
            'lignes.*.description' => 'required|string|max:255',
            'lignes.*.quantite' => 'required|numeric|gt:0',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
            'lignes.*.groupe_taxe_id' => ['nullable', TenantRule::exists('groupes_taxes')],
            'lignes.*.taux_tva' => 'nullable|numeric|between:0,100',
            'lignes.*.reception_ligne_id' => 'nullable|integer',
        ];
    }

    /** Facture affichée : lignes avec leur rapprochement (valeur reçue, écart), récapitulatif des taxes, écart total. */
    private function presenter(FactureFournisseur $facture): array
    {
        $rapprochement = $this->factures->rapprochement($facture);
        $donnees = $facture->toArray();
        $donnees['lignes'] = $facture->lignes->map(fn ($ligne) => array_merge($ligne->toArray(), $rapprochement[$ligne->id] ?? []))->all();
        $donnees['taxes'] = $this->taxes->recapitulatif('facture_fournisseur', $facture->lignes->pluck('id'));
        $donnees['ecart'] = $rapprochement === [] ? null : round(array_sum(array_column($rapprochement, 'ecart')), 2);

        return $donnees;
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
