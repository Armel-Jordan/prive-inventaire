<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\EcritureComptableLigne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Soldes calculés à partir des lignes d'écritures : balance des comptes sur une période et grand livre d'un compte.
 * Le solde d'ouverture d'une période cumule toutes les écritures antérieures ; une fois un exercice clôturé, son
 * écriture de clôture remet à zéro les comptes de produits et de charges.
 */
class Soldes
{
    /** Lignes au-delà desquelles le grand livre est tronqué (l'export CSV reste complet). */
    public const MAX_LIGNES = 5000;

    /**
     * Débits et crédits par compte des écritures datées entre $du et $au (bornes incluses ; null : sans borne),
     * hors écritures des origines exclues.
     *
     * @return Collection<int, object{compte_id: int, debit: float, credit: float}>
     */
    public function mouvements(?string $du, ?string $au, ?array $compteIds = null, array $sourcesExclues = []): Collection
    {
        return EcritureComptableLigne::query()
            ->join('ecritures_comptables as e', 'e.id', '=', 'ecritures_comptables_lignes.ecriture_id')
            ->when($du, fn ($q) => $q->whereDate('e.date', '>=', $du))
            ->when($au, fn ($q) => $q->whereDate('e.date', '<=', $au))
            ->when($compteIds !== null, fn ($q) => $q->whereIn('ecritures_comptables_lignes.compte_id', $compteIds))
            ->when($sourcesExclues, fn ($q) => $q->whereNotIn('e.source', $sourcesExclues))
            ->groupBy('ecritures_comptables_lignes.compte_id')
            ->selectRaw('ecritures_comptables_lignes.compte_id as compte_id, SUM(ecritures_comptables_lignes.debit) as debit, SUM(ecritures_comptables_lignes.credit) as credit')
            ->toBase()->get()
            ->map(fn ($m) => (object) ['compte_id' => (int) $m->compte_id, 'debit' => round((float) $m->debit, 2), 'credit' => round((float) $m->credit, 2)])
            ->keyBy('compte_id');
    }

    /**
     * Balance des comptes entre deux dates : solde d'ouverture (écritures antérieures), mouvements, solde final.
     * Seuls les comptes qui ont un solde ou un mouvement y figurent.
     */
    public function balance(string $du, string $au): array
    {
        $avant = $this->mouvements(null, Carbon::parse($du)->subDay()->toDateString());
        $pendant = $this->mouvements($du, $au);
        $lignes = [];
        $totaux = array_fill_keys(['ouverture_debit', 'ouverture_credit', 'mouvement_debit', 'mouvement_credit', 'solde_debit', 'solde_credit'], 0.0);
        foreach (CompteComptable::orderBy('numero')->get() as $compte) {
            $ouverture = round(($avant[$compte->id]->debit ?? 0) - ($avant[$compte->id]->credit ?? 0), 2);
            $debit = $pendant[$compte->id]->debit ?? 0.0;
            $credit = $pendant[$compte->id]->credit ?? 0.0;
            if ($ouverture == 0 && $debit == 0 && $credit == 0) {
                continue;
            }
            $solde = round($ouverture + $debit - $credit, 2);
            $ligne = [
                'compte_id' => $compte->id,
                'numero' => $compte->numero,
                'libelle' => $compte->libelle,
                'nature' => $compte->nature,
                'ouverture_debit' => $ouverture > 0 ? $ouverture : 0.0,
                'ouverture_credit' => $ouverture < 0 ? -$ouverture : 0.0,
                'mouvement_debit' => (float) $debit,
                'mouvement_credit' => (float) $credit,
                'solde_debit' => $solde > 0 ? $solde : 0.0,
                'solde_credit' => $solde < 0 ? -$solde : 0.0,
            ];
            foreach ($totaux as $cle => $valeur) {
                $totaux[$cle] = round($valeur + $ligne[$cle], 2);
            }
            $lignes[] = $ligne;
        }

        return [
            'du' => $du,
            'au' => $au,
            'comptes' => $lignes,
            'totaux' => $totaux,
            'equilibree' => $totaux['mouvement_debit'] == $totaux['mouvement_credit'] && $totaux['solde_debit'] == $totaux['solde_credit'],
        ];
    }

    /**
     * Grand livre d'un compte (et de ses sous-comptes) entre deux dates : solde d'ouverture, écritures avec solde
     * progressif, totaux et solde final.
     */
    public function grandLivre(CompteComptable $compte, string $du, string $au, ?string $recherche = null): array
    {
        $ids = $this->descendance($compte);
        $avant = $this->mouvements(null, Carbon::parse($du)->subDay()->toDateString(), $ids);
        $ouverture = round($avant->sum('debit') - $avant->sum('credit'), 2);

        $requete = EcritureComptableLigne::query()
            ->join('ecritures_comptables as e', 'e.id', '=', 'ecritures_comptables_lignes.ecriture_id')
            ->join('journaux_comptables as j', 'j.id', '=', 'e.journal_id')
            ->join('comptes_comptables as c', 'c.id', '=', 'ecritures_comptables_lignes.compte_id')
            ->whereIn('ecritures_comptables_lignes.compte_id', $ids)
            ->whereDate('e.date', '>=', $du)->whereDate('e.date', '<=', $au)
            ->when(filled($recherche), fn ($q) => $q->where(fn ($r) => $r->where('e.libelle', 'like', "%{$recherche}%")
                ->orWhere('ecritures_comptables_lignes.libelle', 'like', "%{$recherche}%")
                ->orWhere('e.numero', 'like', "%{$recherche}%")
                ->orWhere('e.reference', 'like', "%{$recherche}%")))
            ->orderBy('e.date')->orderBy('e.numero')->orderBy('ecritures_comptables_lignes.ordre')
            ->select([
                'e.id as ecriture_id', 'e.date', 'e.numero', 'e.libelle as libelle_ecriture', 'e.reference', 'e.source', 'j.code as journal',
                'c.numero as compte', 'ecritures_comptables_lignes.libelle', 'ecritures_comptables_lignes.debit', 'ecritures_comptables_lignes.credit',
            ]);

        $solde = $ouverture;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $lignes = [];
        foreach ($requete->toBase()->limit(self::MAX_LIGNES + 1)->get() as $ligne) {
            if (count($lignes) === self::MAX_LIGNES) {
                break;
            }
            $debit = round((float) $ligne->debit, 2);
            $credit = round((float) $ligne->credit, 2);
            $solde = round($solde + $debit - $credit, 2);
            $totalDebit += $debit;
            $totalCredit += $credit;
            $lignes[] = [
                'ecriture_id' => (int) $ligne->ecriture_id,
                'date' => Carbon::parse($ligne->date)->toDateString(),
                'numero' => $ligne->numero,
                'journal' => $ligne->journal,
                'compte' => $ligne->compte,
                'libelle' => $ligne->libelle ?: $ligne->libelle_ecriture,
                'reference' => $ligne->reference,
                // Origine de l'écriture (saisie manuelle, facture, encaissement, transaction bancaire…).
                'source' => $ligne->source,
                'debit' => $debit,
                'credit' => $credit,
                'solde' => $solde,
            ];
        }
        $tronque = $requete->toBase()->count() > self::MAX_LIGNES;

        return [
            'compte' => $compte->only(['id', 'numero', 'libelle', 'nature', 'type']),
            'du' => $du,
            'au' => $au,
            'solde_ouverture' => $ouverture,
            'lignes' => $lignes,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            // Sans recherche : solde au dernier jour ; avec, le solde des seules lignes trouvées n'aurait pas de sens.
            'solde_final' => filled($recherche) ? null : round($ouverture + $totalDebit - $totalCredit, 2),
            'tronque' => $tronque,
        ];
    }

    /** Le compte et tous ses sous-comptes (un en-tête cumule ses sous-comptes). */
    public function descendance(CompteComptable $compte): array
    {
        $parParent = CompteComptable::get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $file = [$compte->id];
        while ($file) {
            $id = array_shift($file);
            $ids[] = $id;
            foreach ($parParent[$id] ?? [] as $enfant) {
                $file[] = $enfant->id;
            }
        }

        return $ids;
    }
}
