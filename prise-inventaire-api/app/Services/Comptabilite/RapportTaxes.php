<?php

namespace App\Services\Comptabilite;

use App\Models\Avoir;
use App\Models\CompteComptable;
use App\Models\DocumentTaxe;
use App\Models\EcritureComptable;
use App\Models\Facture;
use App\Models\FactureFournisseur;
use App\Models\ParametreComptable;
use App\Models\TenantTaxe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Rapport des taxes (plan de parité fripv2, phase 4.8, « Rapport de taxes ») sur une période :
 * - taxes collectées sur les ventes (factures émises, y compris créditées ; les notes de crédit les diminuent) et
 *   déductibles sur les achats (factures fournisseurs enregistrées), taxe par taxe, dans la devise de l'entreprise ;
 * - une ligne de document taxée par groupe garde le détail de chaque taxe ; une ligne au taux simple compte pour
 *   « TVA » à son taux ;
 * - trois niveaux : le détail (un document par ligne), un total par jour, ou le total de la période ;
 * - le sommaire par taxe (collectée, déductible, différence à reverser ou à récupérer) et, la comptabilisation étant
 *   réglée, les comptes de taxes du journal sur la période, pour rapprocher les deux.
 */
class RapportTaxes
{
    public const NIVEAUX = ['detail', 'jour', 'periode'];

    /** Factures dont les taxes sont dues : émises, en partie payées, payées ou créditées (la note de crédit déduit). */
    private const FACTURES = ['emise', 'partiellement_payee', 'payee', 'creditee'];

    /** Documents au-delà desquels le détail s'arrête (les totaux restent complets). */
    public const MAX_DETAIL = 5000;

    public function __construct(private readonly Soldes $soldes) {}

    public function rapport(string $du, string $au, string $niveau): array
    {
        $noms = [];
        $documents = $this->documents($du, $au, $noms);
        $cles = array_keys($noms);
        sort($cles);
        $taxes = array_map(fn (string $cle) => ['cle' => $cle, 'nom' => $noms[$cle]], $cles);

        $totaux = $this->total($documents);
        $lignes = match ($niveau) {
            'detail' => $documents->take(self::MAX_DETAIL)->values()->all(),
            'jour' => $documents->groupBy('date')->map(fn (Collection $jour, string $date) => ['date' => $date] + $this->total($jour))->values()->all(),
            default => [['date' => null] + $totaux],
        };
        $sommaire = array_map(function (array $taxe) use ($totaux) {
            $collectee = $totaux['collectee'][$taxe['cle']] ?? 0.0;
            $deductible = $totaux['deductible'][$taxe['cle']] ?? 0.0;

            return $taxe + ['collectee' => $collectee, 'deductible' => $deductible, 'difference' => round($collectee - $deductible, 2)];
        }, $taxes);
        $collectee = round(array_sum(array_column($sommaire, 'collectee')), 2);
        $deductible = round(array_sum(array_column($sommaire, 'deductible')), 2);

        return [
            'du' => $du,
            'au' => $au,
            'niveau' => $niveau,
            'taxes' => $taxes,
            'lignes' => $lignes,
            'tronque' => $niveau === 'detail' && $documents->count() > self::MAX_DETAIL,
            'totaux' => $totaux,
            'sommaire' => $sommaire,
            'total' => ['collectee' => $collectee, 'deductible' => $deductible, 'difference' => round($collectee - $deductible, 2)],
            'comptes' => $this->comptes($du, $au),
        ];
    }

    /**
     * Ventes, notes de crédit et achats de la période, dans l'ordre des dates : montant HT et taxes, dans la devise de
     * l'entreprise (une note de crédit en négatif).
     *
     * @param  array<string, string>  $noms  clé de taxe => nom, complété au passage
     */
    private function documents(string $du, string $au, array &$noms): Collection
    {
        $entre = fn ($q, string $champ) => $q->whereDate($champ, '>=', $du)->whereDate($champ, '<=', $au);
        $sources = [
            ['vente', 'facture', 'date_facture', 1, $entre(Facture::query()->whereIn('statut', self::FACTURES), 'date_facture')->with(['client', 'lignes'])->get()],
            ['avoir', 'avoir', 'date_avoir', -1, $entre(Avoir::query()->whereIn('statut', Avoir::EMISES), 'date_avoir')->with(['client', 'lignes'])->get()],
            ['achat', 'facture_fournisseur', 'date_facture', 1, $entre(FactureFournisseur::query()->where('statut', FactureFournisseur::ENREGISTREE), 'date_facture')->with(['fournisseur', 'lignes'])->get()],
        ];
        $actuels = TenantTaxe::query()->pluck('nom', 'id');
        $documents = collect();
        foreach ($sources as [$type, $document, $champDate, $signe, $liste]) {
            $detail = $this->detailTaxes($document, $liste->flatMap(fn (Model $d) => $d->lignes->pluck('id'))->all());
            foreach ($liste as $piece) {
                $taux = (float) ($piece->taux_change ?: 1);
                $ht = 0.0;
                $taxes = [];
                foreach ($piece->lignes as $ligne) {
                    $ht += (float) $ligne->montant_ht;
                    $parTaxe = $detail->get($ligne->id);
                    if ($parTaxe && $parTaxe->isNotEmpty()) {
                        foreach ($parTaxe as $taxe) {
                            $cle = $taxe->tenant_taxe_id ? "taxe:{$taxe->tenant_taxe_id}" : 'nom:'.mb_strtolower($taxe->nom).':'.$this->taux($taxe->taux);
                            $noms[$cle] ??= ($taxe->tenant_taxe_id ? ($actuels[$taxe->tenant_taxe_id] ?? null) : null) ?: $taxe->nom;
                            $taxes[$cle] = ($taxes[$cle] ?? 0) + (float) $taxe->montant;
                        }
                    } elseif ((float) $ligne->montant_tva != 0) {
                        // Ligne au taux simple : la TVA à son taux.
                        $cle = 'tva:'.$this->taux($ligne->taux_tva);
                        $noms[$cle] ??= 'TVA '.str_replace('.', ',', $this->taux($ligne->taux_tva)).' %';
                        $taxes[$cle] = ($taxes[$cle] ?? 0) + (float) $ligne->montant_tva;
                    }
                }
                $montants = array_map(fn (float $m) => round($signe * $m * $taux, 2), $taxes);
                $htSigne = round($signe * $ht * $taux, 2);
                $documents->push([
                    'date' => $piece->{$champDate}->toDateString(),
                    'type' => $type,
                    'id' => $piece->id,
                    'numero' => $piece->numero,
                    'reference' => $type === 'achat' ? $piece->numero_fournisseur : null,
                    'tiers' => $type === 'achat' ? (string) $piece->fournisseur?->raison_sociale
                        : (string) ($piece->passage_nom ?: $piece->client?->raison_sociale ?: $piece->client?->nom ?: ''),
                    'ventes_ht' => $type === 'achat' ? 0.0 : $htSigne,
                    'achats_ht' => $type === 'achat' ? $htSigne : 0.0,
                    'collectee' => $type === 'achat' ? [] : $montants,
                    'deductible' => $type === 'achat' ? $montants : [],
                ]);
            }
        }

        // Par date, puis ventes, notes de crédit et achats, puis numéro.
        $ordre = ['vente' => 0, 'avoir' => 1, 'achat' => 2];

        return $documents->sort(fn (array $a, array $b) => [$a['date'], $ordre[$a['type']], $a['numero']] <=> [$b['date'], $ordre[$b['type']], $b['numero']])->values();
    }

    /** Détail des taxes des lignes d'un type de document : ligne => taxes. */
    private function detailTaxes(string $document, array $lignes): Collection
    {
        $detail = collect();
        foreach (array_chunk($lignes, 500) as $paquet) {
            $detail = $detail->merge(DocumentTaxe::query()->where('document', $document)->whereIn('ligne_id', $paquet)->get());
        }

        return $detail->groupBy('ligne_id');
    }

    /** Totaux d'un ensemble de documents : ventes et achats HT, taxes collectées et déductibles par taxe. */
    private function total(Collection $documents): array
    {
        $somme = function (string $champ) use ($documents) {
            $taxes = [];
            foreach ($documents as $document) {
                foreach ($document[$champ] as $cle => $montant) {
                    $taxes[$cle] = ($taxes[$cle] ?? 0) + $montant;
                }
            }

            return array_map(fn (float $m) => round($m, 2), $taxes);
        };

        return [
            'ventes_ht' => round((float) $documents->sum('ventes_ht'), 2),
            'achats_ht' => round((float) $documents->sum('achats_ht'), 2),
            'collectee' => $somme('collectee'),
            'deductible' => $somme('deductible'),
        ];
    }

    /**
     * Comptes de taxes du journal sur la période (comptabilisation réglée) : ceux de la TVA collectée et déductible et
     * ceux de chaque taxe ; le solde (crédit − débit) de l'ensemble est la taxe nette à reverser (négatif : à récupérer).
     * Null si la comptabilisation n'est pas réglée.
     */
    private function comptes(string $du, string $au): ?array
    {
        $parametres = ParametreComptable::query()->first();
        if (! $parametres) {
            return null;
        }
        $roles = [];
        $ajouter = function (mixed $id, string $role) use (&$roles) {
            if ($id) {
                $roles[(int) $id][$role] = true;
            }
        };
        $ajouter($parametres->compte_tva_collectee_id, 'collectee');
        $ajouter($parametres->compte_tva_deductible_id, 'deductible');
        foreach (($parametres->correspondances['taxes'] ?? []) as $comptes) {
            $ajouter($comptes['collectee'] ?? null, 'collectee');
            $ajouter($comptes['deductible'] ?? null, 'deductible');
        }
        if ($roles === []) {
            return null;
        }
        $mouvements = $this->soldes->mouvements($du, $au, array_keys($roles), [EcritureComptable::CLOTURE]);
        $lignes = CompteComptable::query()->whereIn('id', array_keys($roles))->orderBy('numero')->get(['id', 'numero', 'libelle'])
            ->map(fn (CompteComptable $compte) => [
                'id' => $compte->id,
                'numero' => $compte->numero,
                'libelle' => $compte->libelle,
                'roles' => array_keys($roles[$compte->id]),
                'debit' => (float) ($mouvements[$compte->id]->debit ?? 0),
                'credit' => (float) ($mouvements[$compte->id]->credit ?? 0),
                'solde' => round((float) ($mouvements[$compte->id]->credit ?? 0) - (float) ($mouvements[$compte->id]->debit ?? 0), 2),
            ])->values()->all();

        return ['lignes' => $lignes, 'solde' => round(array_sum(array_column($lignes, 'solde')), 2)];
    }

    /** Taux lisible : « 19.25 », « 10 ». */
    private function taux(mixed $taux): string
    {
        return rtrim(rtrim(number_format((float) $taux, 4, '.', ''), '0'), '.');
    }
}
