<?php

namespace App\Services\Banque;

use App\Models\CompteBancaire;
use App\Models\EcritureComptableLigne;
use App\Models\ReleveBancaire;
use App\Models\ReleveBancaireLien;
use App\Models\ReleveBancaireLigne;
use App\Services\Comptabilite\Comptabilisation;
use App\Services\DevisesVente;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relevés bancaires importés (plan de parité fripv2, phase 4.6 : « Flux bancaire », ici par fichier CSV, OFX ou CAMT) :
 * - import d'un fichier pour un compte bancaire relié : les opérations nouvelles (un réimport ne double rien), aperçu
 *   possible ; les colonnes du CSV de la banque sont mémorisées sur le compte ;
 * - chaque opération se relie aux mouvements du compte comptable du compte bancaire (une opération peut en régler
 *   plusieurs, un mouvement n'est relié qu'à une opération) : reliée quand les montants concordent au centime, en partie
 *   sinon ; elle s'exclut (motif) si elle n'a pas de mouvement ;
 * - meilleure correspondance proposée, jamais appliquée seule : un seul mouvement libre du même montant au centime, daté
 *   à 5 jours près, qu'aucune autre opération libre ne revendique ; les autres candidats : même sens, à 30 jours près,
 *   les plus proches en montant puis en date, ou une recherche sur toutes les dates ;
 * - « classer » une opération sans mouvement crée la transaction bancaire correspondante (frais, intérêts…), la passe
 *   au journal et la relie ;
 * - relier ne pointe rien : le rapprochement pointe d'un geste ce qui est relié jusqu'à la date du relevé.
 */
class RelevesBancaires
{
    private const JOURS_CORRESPONDANCE = 5;

    private const JOURS_CANDIDATS = 30;

    private const MAX_CANDIDATS = 200;

    public function __construct(private readonly LecteurReleves $lecteur) {}

    /**
     * Importe (ou simule l'import d') un fichier de relevé pour un compte bancaire relié.
     *
     * @param  array<string, mixed>  $reglages  colonnes du CSV (voir LecteurReleves::lireCsv)
     */
    public function importer(CompteBancaire $compte, string $fichier, string $contenu, array $reglages, bool $simuler, ?int $auteur): array
    {
        // Le journal compte dans la devise de l'entreprise : les montants d'un relevé en devise ne s'y comparent pas.
        $reference = app(DevisesVente::class)->reference();
        if (! DevisesVente::memeDevise($compte->devise_code ?: $reference, $reference)) {
            throw new DomainException(__("Le compte « :nom » est en :devise : les relevés s'importent pour les comptes dans la devise de l'entreprise (:reference), celle du journal.", [
                'nom' => $compte->nom,
                'devise' => $compte->devise_code,
                'reference' => $reference,
            ]));
        }
        $this->exigerRelie($compte);
        $format = $this->lecteur->estOfx($contenu) ? 'ofx' : ($this->lecteur->estCamt($contenu) ? 'camt' : 'csv');
        $lu = match ($format) {
            'ofx' => $this->lecteur->lireOfx($contenu),
            'camt' => $this->lecteur->lireCamt($contenu),
            default => $this->lecteur->lireCsv($contenu, $reglages),
        };
        $existants = ReleveBancaireLigne::query()->where('compte_bancaire_id', $compte->id)
            ->whereIn('identifiant', array_column($lu['lignes'], 'identifiant'))->pluck('identifiant')->flip();
        $nouvelles = [];
        $vus = [];
        $doublons = 0;
        foreach ($lu['lignes'] as $ligne) {
            if (isset($existants[$ligne['identifiant']]) || isset($vus[$ligne['identifiant']])) {
                $doublons++;

                continue;
            }
            $vus[$ligne['identifiant']] = true;
            $nouvelles[] = $ligne;
        }
        $rapport = [
            'format' => $format,
            'lues' => count($lu['lignes']),
            'nouvelles' => count($nouvelles),
            'doublons' => $doublons,
            'ignorees' => $lu['ignorees'],
            'total' => round(array_sum(array_column($nouvelles, 'montant')), 2),
            'apercu' => array_slice($nouvelles, 0, 50),
        ];
        if ($simuler || $nouvelles === []) {
            return $rapport + ['releve_id' => null];
        }

        $releve = DB::transaction(function () use ($compte, $fichier, $format, $nouvelles, $doublons, $reglages, $auteur) {
            $releve = ReleveBancaire::create([
                'compte_bancaire_id' => $compte->id, 'fichier' => mb_substr($fichier, 0, 255), 'format' => $format,
                'nombre_lignes' => count($nouvelles), 'doublons' => $doublons, 'importe_par' => $auteur,
            ]);
            foreach ($nouvelles as $ligne) {
                ReleveBancaireLigne::create([
                    'releve_id' => $releve->id, 'compte_bancaire_id' => $compte->id, 'date_operation' => $ligne['date'], 'libelle' => $ligne['libelle'],
                    'reference' => $ligne['reference'], 'montant' => $ligne['montant'], 'identifiant' => $ligne['identifiant'],
                ]);
            }
            if ($format === 'csv') {
                $compte->update(['import_reglages' => array_intersect_key($reglages, array_flip([
                    'colonne_date', 'colonne_libelle', 'colonne_montant', 'colonne_debit', 'colonne_credit', 'colonne_reference', 'ligne_debut', 'format_date',
                ]))]);
            }

            return $releve;
        });

        return $rapport + ['releve_id' => $releve->id];
    }

    /** Fichiers importés d'un compte, les plus récents d'abord : période couverte, opérations, doublons, reliées. */
    public function imports(CompteBancaire $compte): array
    {
        return ReleveBancaire::query()->where('compte_bancaire_id', $compte->id)->with('auteur:id,nom')
            ->withCount(['lignes as reliees' => fn ($q) => $q->whereHas('liens')])
            ->withMin('lignes', 'date_operation')->withMax('lignes', 'date_operation')
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn (ReleveBancaire $r) => [
                'id' => $r->id,
                'fichier' => $r->fichier,
                'format' => $r->format,
                'importe_le' => $r->created_at?->toIso8601String(),
                'auteur' => $r->auteur?->nom,
                'du' => $r->lignes_min_date_operation ? Carbon::parse($r->lignes_min_date_operation)->toDateString() : null,
                'au' => $r->lignes_max_date_operation ? Carbon::parse($r->lignes_max_date_operation)->toDateString() : null,
                'nombre_lignes' => (int) $r->nombre_lignes,
                'doublons' => (int) $r->doublons,
                'reliees' => (int) $r->reliees,
            ])->all();
    }

    /** Supprime un fichier importé et ses opérations, si aucune n'est reliée. */
    public function supprimerImport(ReleveBancaire $releve): void
    {
        if ($releve->lignes()->whereHas('liens')->exists()) {
            throw new DomainException(__("Des opérations de ce relevé sont reliées à des mouvements : déliez-les d'abord."));
        }
        DB::transaction(function () use ($releve) {
            $releve->lignes()->delete();
            $releve->delete();
        });
    }

    /**
     * Opérations importées d'un compte bancaire sur une période, avec leurs mouvements reliés, leur statut (non reliée,
     * en partie, reliée, exclue) et la meilleure correspondance proposée.
     *
     * @param  array{du?: ?string, au?: ?string, statut?: ?string}  $filtres
     */
    public function lignes(CompteBancaire $compte, array $filtres): array
    {
        $au = $filtres['au'] ?? today()->toDateString();
        $du = $filtres['du'] ?? Carbon::parse($au)->subDays(90)->toDateString();
        $lignes = ReleveBancaireLigne::query()->where('compte_bancaire_id', $compte->id)
            ->whereDate('date_operation', '>=', $du)->whereDate('date_operation', '<=', $au)
            ->with(['liens.ecritureLigne.ecriture:id,numero,date,libelle,reference,journal_id,source', 'liens.ecritureLigne.ecriture.journal:id,code'])
            ->orderByDesc('date_operation')->orderByDesc('id')->get();
        $presentees = $lignes->map(fn (ReleveBancaireLigne $l) => $this->presenter($l));
        $suggestions = $this->suggestions($compte, $presentees->where('statut', 'non_reliee')->values());
        $presentees = $presentees->map(fn (array $l) => $l + ['suggestion' => $suggestions[$l['id']] ?? null]);
        $statut = $filtres['statut'] ?? null;

        return [
            'du' => $du,
            'au' => $au,
            'lignes' => ($statut ? $presentees->where('statut', $statut) : $presentees)->values()->all(),
            'resume' => [
                'non_reliee' => $presentees->where('statut', 'non_reliee')->count(),
                'partielle' => $presentees->where('statut', 'partielle')->count(),
                'reliee' => $presentees->where('statut', 'reliee')->count(),
                'exclue' => $presentees->where('statut', 'exclue')->count(),
                'suggestions' => count($suggestions),
            ],
        ];
    }

    /** Mouvements libres qui peuvent correspondre à une opération : même sens, à 30 jours près, ou une recherche. */
    public function candidats(ReleveBancaireLigne $ligne, ?string $recherche): array
    {
        $compteId = $this->exigerRelie($ligne->compteBancaire);
        $montant = abs((float) $ligne->montant);
        $date = $ligne->date_operation->toDateString();
        $requete = $this->mouvementsLibres($compteId)
            ->where((float) $ligne->montant > 0 ? 'ecritures_comptables_lignes.debit' : 'ecritures_comptables_lignes.credit', '>', 0);
        $recherche = trim((string) $recherche);
        if ($recherche !== '') {
            $nombre = str_replace([' ', ','], ['', '.'], $recherche);
            $requete->where(fn ($q) => $q->where('e.numero', 'like', "%{$recherche}%")->orWhere('e.libelle', 'like', "%{$recherche}%")
                ->orWhere('ecritures_comptables_lignes.libelle', 'like', "%{$recherche}%")->orWhere('e.reference', 'like', "%{$recherche}%")
                ->when(is_numeric($nombre), fn ($q) => $q->orWhere('ecritures_comptables_lignes.debit', (float) $nombre)->orWhere('ecritures_comptables_lignes.credit', (float) $nombre)));
        } else {
            $requete->whereDate('e.date', '>=', Carbon::parse($date)->subDays(self::JOURS_CANDIDATS)->toDateString())
                ->whereDate('e.date', '<=', Carbon::parse($date)->addDays(self::JOURS_CANDIDATS)->toDateString());
        }

        return $requete->limit(1000)->get()
            ->map(fn ($m) => $this->mouvement($m))
            ->sortBy([
                fn (array $a, array $b) => abs($a['montant'] - $montant) <=> abs($b['montant'] - $montant),
                fn (array $a, array $b) => abs(strtotime($a['date']) - strtotime($date)) <=> abs(strtotime($b['date']) - strtotime($date)),
            ])->take(self::MAX_CANDIDATS)->values()->all();
    }

    /** Relie une opération à des mouvements libres de son compte, dans le même sens. */
    public function relier(ReleveBancaireLigne $ligne, array $ids): array
    {
        $compteId = $this->exigerRelie($ligne->compteBancaire);
        if ($ligne->exclue) {
            throw new DomainException(__("Cette opération est exclue : rétablissez-la d'abord."));
        }
        $this->lier(function () use ($ligne, $ids, $compteId) {
            $mouvements = $this->mouvementsLibres($compteId)->whereIn('ecritures_comptables_lignes.id', $ids)
                ->where((float) $ligne->montant > 0 ? 'ecritures_comptables_lignes.debit' : 'ecritures_comptables_lignes.credit', '>', 0)->lockForUpdate()->get();
            if ($mouvements->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lignes' => __("Choisissez des mouvements libres de ce compte, dans le sens de l'opération.")]);
            }
            foreach ($mouvements as $mouvement) {
                ReleveBancaireLien::create(['releve_ligne_id' => $ligne->id, 'ecriture_ligne_id' => $mouvement->id]);
            }
        });

        return $this->presenter($ligne->fresh());
    }

    /** Retire un mouvement (ou tous) d'une opération. */
    public function delier(ReleveBancaireLigne $ligne, ?int $ecritureLigneId): array
    {
        ReleveBancaireLien::query()->where('releve_ligne_id', $ligne->id)
            ->when($ecritureLigneId, fn ($q, $id) => $q->where('ecriture_ligne_id', $id))->delete();

        return $this->presenter($ligne->fresh());
    }

    /** Exclut une opération sans mouvement (motif) ; la rétablit. */
    public function exclure(ReleveBancaireLigne $ligne, ?string $motif): array
    {
        if ($ligne->liens()->exists()) {
            throw new DomainException(__("Une opération reliée ne s'exclut pas : déliez d'abord ses mouvements."));
        }
        $ligne->update(['exclue' => true, 'motif_exclusion' => trim((string) $motif) ?: null]);

        return $this->presenter($ligne);
    }

    public function retablir(ReleveBancaireLigne $ligne): array
    {
        $ligne->update(['exclue' => false, 'motif_exclusion' => null]);

        return $this->presenter($ligne);
    }

    /** Applique d'un geste toutes les meilleures correspondances proposées sur la période. */
    public function relierSuggestions(CompteBancaire $compte, array $filtres): int
    {
        $suggestions = collect($this->lignes($compte, $filtres + ['statut' => 'non_reliee'])['lignes'])->filter(fn (array $l) => $l['suggestion']);
        $this->lier(function () use ($suggestions) {
            foreach ($suggestions as $ligne) {
                ReleveBancaireLien::create(['releve_ligne_id' => $ligne['id'], 'ecriture_ligne_id' => $ligne['suggestion']['id']]);
            }
        });

        return $suggestions->count();
    }

    /**
     * Opération sans mouvement : crée la transaction bancaire correspondante (dépôt ou retrait sur le compte de contrepartie
     * choisi), la passe au journal et relie son mouvement. Si elle ne peut pas encore passer au journal (période fermée,
     * réglage manquant), elle reste créée et le motif est donné.
     *
     * @return array{ligne: array<string, mixed>, transaction: array<string, mixed>, message: string}
     */
    public function classer(ReleveBancaireLigne $ligne, int $compteComptableId, ?string $libelle, int $tenantId, ?int $auteur): array
    {
        $compteId = $this->exigerRelie($ligne->compteBancaire);
        if ($ligne->exclue || $ligne->liens()->exists()) {
            throw new DomainException(__('Seule une opération ni reliée ni exclue se classe.'));
        }
        $transaction = app(TransactionsBancaires::class)->enregistrer([
            'compte_bancaire_id' => $ligne->compte_bancaire_id,
            'date_transaction' => $ligne->date_operation->toDateString(),
            'type' => (float) $ligne->montant > 0 ? 'depot' : 'retrait',
            'libelle' => trim((string) $libelle) ?: $ligne->libelle,
            'reference' => $ligne->reference,
            'lignes' => [['compte_comptable_id' => $compteComptableId, 'montant' => abs((float) $ligne->montant)]],
        ], $tenantId, $auteur);

        try {
            $ecriture = app(Comptabilisation::class)->comptabiliserDocument('transaction_bancaire', $transaction->id, $auteur);
            $mouvement = $ecriture->lignes()->where('compte_id', $compteId)->value('id');
            ReleveBancaireLien::create(['releve_ligne_id' => $ligne->id, 'ecriture_ligne_id' => $mouvement]);
            $message = __('Transaction :numero créée, passée au journal (:ecriture) et reliée.', ['numero' => $transaction->numero, 'ecriture' => $ecriture->numero]);
        } catch (DomainException|ValidationException $e) {
            $raison = $e instanceof ValidationException ? (collect($e->errors())->flatten()->first() ?? $e->getMessage()) : $e->getMessage();
            $message = __('Transaction :numero créée ; elle se reliera une fois passée au journal : :raison', ['numero' => $transaction->numero, 'raison' => $raison]);
        }

        return ['ligne' => $this->presenter($ligne->fresh()), 'transaction' => $transaction->toArray(), 'message' => $message];
    }

    /** Lignes reliées à des opérations du relevé datées au plus tard d'une date, pour un compte bancaire. */
    public function mouvementsRelies(int $compteBancaireId, string $au): array
    {
        return ReleveBancaireLien::query()->whereHas('ligne', fn ($q) => $q->where('compte_bancaire_id', $compteBancaireId)->whereDate('date_operation', '<=', $au))
            ->pluck('ecriture_ligne_id')->all();
    }

    /** Opération présentée : statut, montant relié, mouvements reliés. */
    private function presenter(ReleveBancaireLigne $ligne): array
    {
        $ligne->loadMissing(['liens.ecritureLigne.ecriture:id,numero,date,libelle,reference,journal_id,source', 'liens.ecritureLigne.ecriture.journal:id,code']);
        $mouvements = $ligne->liens->map(fn (ReleveBancaireLien $lien) => $lien->ecritureLigne ? [
            'id' => $lien->ecritureLigne->id,
            'date' => $lien->ecritureLigne->ecriture?->date?->toDateString(),
            'numero' => $lien->ecritureLigne->ecriture?->numero,
            'journal' => $lien->ecritureLigne->ecriture?->journal?->code,
            'libelle' => $lien->ecritureLigne->libelle ?: $lien->ecritureLigne->ecriture?->libelle,
            'reference' => $lien->ecritureLigne->ecriture?->reference,
            'source' => $lien->ecritureLigne->ecriture?->source,
            'montant' => round((float) $lien->ecritureLigne->debit - (float) $lien->ecritureLigne->credit, 2),
        ] : null)->filter()->values();
        $relie = round($mouvements->sum('montant'), 2);
        $statut = match (true) {
            $ligne->exclue => 'exclue',
            $mouvements->isEmpty() => 'non_reliee',
            abs($relie - (float) $ligne->montant) < 0.005 => 'reliee',
            default => 'partielle',
        };

        return [
            'id' => $ligne->id,
            'date_operation' => $ligne->date_operation->toDateString(),
            'libelle' => $ligne->libelle,
            'reference' => $ligne->reference,
            'montant' => round((float) $ligne->montant, 2),
            'statut' => $statut,
            'motif_exclusion' => $ligne->motif_exclusion,
            'montant_relie' => $relie,
            'mouvements' => $mouvements->all(),
        ];
    }

    /**
     * Meilleure correspondance de chaque opération libre : un seul mouvement libre du même montant au centime, daté à 5
     * jours près, qu'aucune autre opération libre ne revendique.
     *
     * @param  Collection<int, array<string, mixed>>  $libres
     * @return array<int, array<string, mixed>>
     */
    private function suggestions(CompteBancaire $compte, Collection $libres): array
    {
        if (! $compte->compte_comptable_id || $libres->isEmpty()) {
            return [];
        }
        $du = Carbon::parse($libres->min('date_operation'))->subDays(self::JOURS_CORRESPONDANCE)->toDateString();
        $au = Carbon::parse($libres->max('date_operation'))->addDays(self::JOURS_CORRESPONDANCE)->toDateString();
        $parMontant = $this->mouvementsLibres($compte->compte_comptable_id)->whereDate('e.date', '>=', $du)->whereDate('e.date', '<=', $au)
            ->get()->map(fn ($m) => $this->mouvement($m))->groupBy(fn (array $m) => (string) (int) round($m['signe'] * 100));
        $candidats = $libres->mapWithKeys(fn (array $l) => [$l['id'] => ($parMontant[(string) (int) round($l['montant'] * 100)] ?? collect())
            ->filter(fn (array $m) => abs(strtotime($m['date']) - strtotime($l['date_operation'])) <= self::JOURS_CORRESPONDANCE * 86400)->values()]);
        // Combien d'opérations libres revendiquent chaque mouvement.
        $revendications = $candidats->flatten(1)->countBy('id');

        return $candidats->filter(fn (Collection $c) => $c->count() === 1 && ($revendications[$c->first()['id']] ?? 0) === 1)
            ->map(fn (Collection $c) => $c->first())->all();
    }

    /** Écrit des liens d'un bloc ; un mouvement relié entre-temps à une autre opération (index unique) est signalé. */
    private function lier(callable $ecriture): void
    {
        try {
            DB::transaction($ecriture);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['lignes' => __("Un de ces mouvements vient d'être relié à une autre opération : rechargez.")]);
        }
    }

    /** Lignes du compte comptable qu'aucune opération n'a encore reliées. */
    private function mouvementsLibres(int $compteId)
    {
        return EcritureComptableLigne::query()
            ->join('ecritures_comptables as e', 'e.id', '=', 'ecritures_comptables_lignes.ecriture_id')
            ->join('journaux_comptables as j', 'j.id', '=', 'e.journal_id')
            ->where('ecritures_comptables_lignes.compte_id', $compteId)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('releve_bancaire_liens')
                ->whereColumn('releve_bancaire_liens.ecriture_ligne_id', 'ecritures_comptables_lignes.id'))
            ->select([
                'ecritures_comptables_lignes.id', 'ecritures_comptables_lignes.debit', 'ecritures_comptables_lignes.credit', 'ecritures_comptables_lignes.libelle',
                'e.date', 'e.numero', 'e.libelle as libelle_ecriture', 'e.reference', 'e.source', 'j.code as journal',
            ])
            ->orderBy('e.date')->orderBy('ecritures_comptables_lignes.id');
    }

    private function mouvement(object $m): array
    {
        $signe = round((float) $m->debit - (float) $m->credit, 2);

        return [
            'id' => (int) $m->id,
            'date' => Carbon::parse($m->date)->toDateString(),
            'numero' => $m->numero,
            'journal' => $m->journal,
            'libelle' => $m->libelle ?: $m->libelle_ecriture,
            'reference' => $m->reference,
            'source' => $m->source,
            'montant' => abs($signe),
            'signe' => $signe,
        ];
    }

    private function exigerRelie(?CompteBancaire $compte): int
    {
        if (! $compte?->compte_comptable_id) {
            throw new DomainException(__("Reliez d'abord le compte bancaire « :nom » à son compte comptable.", ['nom' => $compte?->nom]));
        }

        return $compte->compte_comptable_id;
    }
}
