<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debourse;
use App\Models\Fournisseur;
use App\Services\AvisPaiementPdf;
use App\Services\Debourses;
use App\Services\PaiementsDus;
use App\Support\FichierCsv;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Déboursés aux fournisseurs : liste, factures à payer d'un fournisseur (avec l'escompte offert), saisie (répartition
 * sur les factures, escomptes), correction, annulation et avis de paiement. Les règles sont dans App\Services\Debourses.
 */
class DebourseController extends Controller
{
    public function __construct(private readonly Debourses $debourses) {}

    /** Liste filtrable (recherche, fournisseur, mode, statut, période), avec les totaux des déboursés valides. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => 'nullable|string|max:100',
            'fournisseur_id' => 'nullable|integer',
            'mode' => ['nullable', Rule::in(Debourse::MODES)],
            'statut' => ['nullable', Rule::in([Debourse::VALIDE, Debourse::ANNULE])],
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $recherche = trim($filtres['recherche'] ?? '');
        $requete = Debourse::query()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$recherche}%")
                ->orWhere('reference', 'like', "%{$recherche}%")
                ->orWhereHas('fournisseur', fn ($f) => $f->where('raison_sociale', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))
                ->orWhereHas('lignes.facture', fn ($f) => $f->where('numero', 'like', "%{$recherche}%")->orWhere('numero_fournisseur', 'like', "%{$recherche}%"))))
            ->when($filtres['fournisseur_id'] ?? null, fn ($q, $id) => $q->where('fournisseur_id', $id))
            ->when($filtres['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($filtres['date_debut'] ?? null, fn ($q, $date) => $q->whereDate('date_debourse', '>=', $date))
            ->when($filtres['date_fin'] ?? null, fn ($q, $date) => $q->whereDate('date_debourse', '<=', $date));
        $resume = $this->debourses->resume(clone $requete);
        $page = $requete->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->with(['fournisseur:id,code,raison_sociale', 'lignes.facture:id,numero,numero_fournisseur'])
            ->orderByDesc('date_debourse')->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    /** Factures à payer d'un fournisseur dans une devise (vide : la sienne), avec l'escompte offert à la date donnée. */
    public function aPayer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fournisseur_id' => ['required', TenantRule::exists('fournisseurs')],
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'date' => 'nullable|date',
        ]);

        return response()->json($this->debourses->aPayer(
            Fournisseur::query()->findOrFail($donnees['fournisseur_id']), $donnees['devise'] ?? null, $donnees['date'] ?? today()->toDateString(),
        ));
    }

    /**
     * Paiements dus : chaque facture encore due, son échéance, l'escompte encore offert à la date « payer le » et le net
     * à payer, par fournisseur et par devise ; en CSV avec `format=csv`.
     */
    public function paiementsDus(Request $request, PaiementsDus $paiements): JsonResponse|Response
    {
        $filtres = $request->validate([
            'date' => 'nullable|date',
            'payer_le' => 'nullable|date',
            'echeance_au' => 'nullable|date',
            'fournisseur_id' => 'nullable|integer',
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'echus' => 'nullable|boolean',
            'escompte_seulement' => 'nullable|boolean',
            'inclure_soldees' => 'nullable|boolean',
        ]);
        foreach (['echus', 'escompte_seulement', 'inclure_soldees'] as $case) {
            $filtres[$case] = $request->boolean($case);
        }
        $rapport = $paiements->rapport($filtres);
        if ($request->query('format') !== 'csv') {
            return response()->json($rapport);
        }

        $lignes = [];
        foreach ($rapport['groupes'] as $groupe) {
            foreach ($groupe['factures'] as $f) {
                $lignes[] = [$groupe['code_fournisseur'], $groupe['fournisseur'], $f['numero'], $f['numero_fournisseur'], $f['date_facture'], $f['date_echeance'],
                    $f['jours'], $f['montant'], $f['regle'], $f['solde'], $f['limite_escompte'], $f['escompte'], $f['net'], $f['devise'] ?? ''];
            }
            $t = $groupe['totaux'];
            $lignes[] = ['', "Sous-total {$groupe['fournisseur']}", '', '', '', '', '', $t['montant'], $t['regle'], $t['solde'], '', $t['escompte'], $t['net'], $groupe['devise'] ?? ''];
        }

        return FichierCsv::reponse("paiements-dus-{$rapport['date']}.csv",
            ['code', 'fournisseur', 'document', 'numero_fournisseur', 'date', 'echeance', 'jours', 'montant', 'regle', 'solde', 'limite_escompte', 'escompte', 'net_a_payer', 'devise'], $lignes);
    }

    /** Paie une sélection de factures en un geste : un déboursé par fournisseur et par devise. */
    public function payerLot(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'factures' => 'required|array|min:1|max:200',
            'factures.*' => 'integer',
            'date_debourse' => 'required|date|before_or_equal:tomorrow',
            'mode' => ['required', Rule::in(Debourse::MODES)],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            // Appliquer l'escompte encore offert à la date du paiement (oui par défaut).
            'escompte' => 'sometimes|boolean',
        ]);

        return $this->executer(function () use ($donnees, $request) {
            $debourses = $this->debourses->payerLot($donnees, $request->attributes->get('tenant')->id, $request->user()?->id);

            return response()->json([
                'message' => count($debourses).' déboursé(s) enregistré(s) : '.$debourses->pluck('numero')->join(', ').'.',
                'debourses' => $debourses->values(),
            ], 201);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fournisseur_id' => ['required', TenantRule::exists('fournisseurs')],
            'date_debourse' => 'required|date|before_or_equal:tomorrow',
            'mode' => ['required', Rule::in(Debourse::MODES)],
            'reference' => 'nullable|string|max:100',
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'taux_change' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:2000',
            'lignes' => 'required|array|min:1|max:200',
            'lignes.*.facture_fournisseur_id' => 'required|integer',
            'lignes.*.montant' => 'required|numeric|min:0|max:9999999999',
            'lignes.*.escompte' => 'nullable|numeric|min:0|max:9999999999',
        ]);

        return $this->executer(fn () => response()->json(
            $this->debourses->enregistrer($donnees, $request->attributes->get('tenant')->id, $request->user()?->id), 201,
        ));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Debourse::query()->with($this->debourses->relations())->findOrFail($id));
    }

    /** Corrige la référence et les notes. */
    public function update(Request $request, int $id): JsonResponse
    {
        $debourse = Debourse::findOrFail($id);
        $donnees = $request->validate([
            'reference' => 'sometimes|nullable|string|max:100',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->debourses->modifier($debourse, $donnees)));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $debourse = Debourse::findOrFail($id);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->debourses->annuler($debourse, $donnees['motif'] ?? null, $request->user()?->id)));
    }

    /** Avis de paiement envoyé au fournisseur : les factures réglées par le déboursé. */
    public function pdf(int $id, AvisPaiementPdf $pdf): Response
    {
        $debourse = Debourse::findOrFail($id);

        return $pdf->pdf($debourse)->stream("avis-paiement-{$debourse->numero}.pdf");
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
