<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\EcritureComptable;
use App\Models\EcritureComptableLigne;
use App\Models\RecurrenceComptable;
use App\Services\Comptabilite\Ecritures;
use App\Support\FichierCsv;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Journal général : écritures manuelles en partie double, modification tant que leur période est ouverte,
 * contre-passation, export des lignes. Consultation avec le module Finance ; saisie par les administrateurs et
 * responsables (routes).
 */
class EcritureComptableController extends Controller
{
    private const SOURCES = [
        EcritureComptable::MANUELLE, EcritureComptable::OUVERTURE, EcritureComptable::CLOTURE, EcritureComptable::RECURRENTE,
        // Écritures de la comptabilisation automatique, par type de document.
        'facture', 'avoir', 'paiement_client', 'encaissement', 'acompte', 'remboursement_acompte', 'remboursement_avoir', 'facture_fournisseur', 'paiement_fournisseur', 'debourse', 'transaction_bancaire',
    ];

    public function __construct(private Ecritures $ecritures) {}

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'journal_id' => ['nullable', 'integer'],
            'compte_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'recherche' => ['nullable', 'string', 'max:100'],
            // Numéro de récurrence : ses écritures seulement.
            'recurrence' => ['nullable', 'integer'],
        ]);
        $page = EcritureComptable::with(['journal:id,code,libelle', 'lignes.compte:id,numero,libelle', 'createur:id,nom', 'periode.exercice', 'origine:id,numero'])
            ->when($filtres['du'] ?? null, fn ($q, $du) => $q->whereDate('date', '>=', $du))
            ->when($filtres['au'] ?? null, fn ($q, $au) => $q->whereDate('date', '<=', $au))
            ->when($filtres['journal_id'] ?? null, fn ($q, $id) => $q->where('journal_id', $id))
            ->when($filtres['compte_id'] ?? null, fn ($q, $id) => $q->whereHas('lignes', fn ($l) => $l->where('compte_id', $id)))
            ->when($filtres['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filtres['recurrence'] ?? null, fn ($q, $numero) => $q->where('source', EcritureComptable::RECURRENTE)
                ->whereIn('source_id', RecurrenceComptable::query()->where('numero', $numero)->select('id')))
            ->when($filtres['recherche'] ?? null, fn ($q, $texte) => $q->where(fn ($r) => $r->where('libelle', 'like', "%{$texte}%")
                ->orWhere('numero', 'like', "%{$texte}%")->orWhere('reference', 'like', "%{$texte}%")))
            ->orderByDesc('date')->orderByDesc('numero')
            ->paginate(50);
        $inverses = EcritureComptable::whereIn('contrepassation_de', collect($page->items())->pluck('id'))->pluck('numero', 'contrepassation_de');
        $page->getCollection()->transform(fn (EcritureComptable $e) => $this->presenter($e, $inverses[$e->id] ?? null));

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $ecriture = EcritureComptable::findOrFail($id);

        return response()->json($this->presenter($ecriture, EcritureComptable::where('contrepassation_de', $id)->value('numero')));
    }

    public function store(Request $request): JsonResponse
    {
        $ecriture = $this->ecritures->enregistrer($this->valider($request), EcritureComptable::MANUELLE, null, $request->user()?->id);

        return response()->json($this->presenter($ecriture), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $ecriture = EcritureComptable::findOrFail($id);
        if ($raison = $this->ecritures->refusModification($ecriture)) {
            return response()->json(['message' => $raison], 422);
        }
        $ecriture = $this->ecritures->enregistrer($this->valider($request), $ecriture->source, $ecriture);

        return response()->json($this->presenter($ecriture->fresh(), EcritureComptable::where('contrepassation_de', $id)->value('numero')));
    }

    public function destroy(int $id): JsonResponse
    {
        $ecriture = EcritureComptable::findOrFail($id);
        if ($raison = $this->ecritures->refusModification($ecriture)) {
            return response()->json(['message' => $raison], 422);
        }
        if ($inverse = EcritureComptable::where('contrepassation_de', $id)->value('numero')) {
            return response()->json(['message' => "Cette écriture a été contre-passée par {$inverse} : supprimez d'abord la contre-passation."], 422);
        }
        $ecriture->delete();

        return response()->json(['message' => "Écriture {$ecriture->numero} supprimée."]);
    }

    /** Écriture inverse, datée dans une période ouverte : corrige une écriture d'une période fermée. */
    public function contrePasser(Request $request, int $id): JsonResponse
    {
        $ecriture = EcritureComptable::findOrFail($id);
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return response()->json($this->presenter($this->ecritures->contrePasser($ecriture, Carbon::parse($date), $request->user()?->id)), 201);
    }

    /** Lignes des écritures d'une période, pour le cabinet comptable. */
    public function exporter(Request $request): Response
    {
        $dates = $request->validate(['du' => ['required', 'date'], 'au' => ['required', 'date', 'after_or_equal:du']]);
        $lignes = EcritureComptableLigne::query()
            ->join('ecritures_comptables as e', 'e.id', '=', 'ecritures_comptables_lignes.ecriture_id')
            ->join('journaux_comptables as j', 'j.id', '=', 'e.journal_id')
            ->join('comptes_comptables as c', 'c.id', '=', 'ecritures_comptables_lignes.compte_id')
            ->whereDate('e.date', '>=', $dates['du'])->whereDate('e.date', '<=', $dates['au'])
            ->orderBy('e.date')->orderBy('e.numero')->orderBy('ecritures_comptables_lignes.ordre')
            ->select(['e.date', 'j.code as journal', 'e.numero', 'c.numero as compte', 'c.libelle as compte_libelle', 'e.libelle as libelle_ecriture',
                'ecritures_comptables_lignes.libelle', 'e.reference', 'ecritures_comptables_lignes.debit', 'ecritures_comptables_lignes.credit'])
            ->toBase()->cursor()
            ->map(fn ($l) => [Carbon::parse($l->date)->toDateString(), $l->journal, $l->numero, $l->compte, $l->compte_libelle,
                $l->libelle ?: $l->libelle_ecriture, $l->reference, (float) $l->debit, (float) $l->credit]);

        return FichierCsv::reponse("ecritures-{$dates['du']}-{$dates['au']}.csv",
            ['date', 'journal', 'numero', 'compte', 'libelle_compte', 'libelle', 'reference', 'debit', 'credit'], $lignes);
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'journal_id' => ['required', 'integer', TenantRule::exists('journaux_comptables')],
            'date' => ['required', 'date'],
            'libelle' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lignes' => ['required', 'array', 'min:2', 'max:500'],
            'lignes.*.compte_id' => ['required', 'integer', TenantRule::exists('comptes_comptables')],
            'lignes.*.libelle' => ['nullable', 'string', 'max:255'],
            'lignes.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'lignes.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ], [
            'lignes.min' => 'Une écriture compte au moins deux lignes.',
            'lignes.*.compte_id.required' => 'Choisissez le compte de chaque ligne.',
        ]);
    }

    private function presenter(EcritureComptable $ecriture, ?string $contrepasseePar = null): array
    {
        $ecriture->loadMissing(['journal:id,code,libelle', 'lignes.compte:id,numero,libelle', 'createur:id,nom', 'periode.exercice', 'origine:id,numero']);

        return [
            'id' => $ecriture->id,
            'numero' => $ecriture->numero,
            'date' => $ecriture->date->toDateString(),
            'libelle' => $ecriture->libelle,
            'reference' => $ecriture->reference,
            'source' => $ecriture->source,
            'total' => (float) $ecriture->total,
            'journal' => $ecriture->journal?->only(['id', 'code', 'libelle']),
            'createur' => $ecriture->createur?->nom,
            'contrepassation_de' => $ecriture->origine?->numero,
            'contrepassee_par' => $contrepasseePar,
            // Numéro de la récurrence qui a créé l'écriture (écriture récurrente).
            'recurrence' => $ecriture->source === EcritureComptable::RECURRENTE && $ecriture->source_id
                ? RecurrenceComptable::query()->whereKey($ecriture->source_id)->value('numero') : null,
            'modifiable' => $this->ecritures->refusModification($ecriture) === null,
            'lignes' => $ecriture->lignes->map(fn (EcritureComptableLigne $ligne) => [
                'id' => $ligne->id,
                'compte_id' => $ligne->compte_id,
                'compte' => $ligne->compte?->numero,
                'compte_libelle' => $ligne->compte?->libelle,
                'libelle' => $ligne->libelle,
                'debit' => (float) $ligne->debit,
                'credit' => (float) $ligne->credit,
            ])->values(),
        ];
    }
}
