<?php

namespace App\Services\Comptabilite;

use App\Models\AuditLog;
use App\Models\BudgetComptable;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\ExerciceComptable;
use App\Models\PeriodeComptable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budgets (plan de parité fripv2, phase 4.7, « Budget annuel par compte ») :
 * - une feuille par exercice : en lignes les comptes budgétables (et ceux qui ont déjà un budget), en colonnes ses
 *   périodes ; chaque cellule s'enregistre seule, un montant nul la retire ; le total du compte, et pour repère celui
 *   de l'exercice précédent ; produits, charges et résultat budgétés ;
 * - répartir un total également entre les périodes ; copier le budget d'un autre exercice, ajusté d'un pourcentage
 *   (fripv2 ne copie pas) ;
 * - budget et réel sur une suite de périodes (fripv2 n'a pas cette comparaison) : le réel est le mouvement du journal,
 *   hors écriture de clôture, dans le sens naturel du compte ; l'écart, le taux de réalisation, favorable ou non ; les
 *   produits et charges hors budget à part, pour que le résultat réel reste celui du compte de résultat ;
 * - le budget d'un exercice clôturé ne se modifie plus.
 */
class Budgets
{
    /** Natures dont le sens naturel est le débit ; les autres (passif, capitaux propres, produit) : le crédit. */
    private const NATURES_DEBIT = ['actif', 'charge'];

    public function __construct(private readonly Soldes $soldes) {}

    /** Exercice proposé : celui du jour, sinon le plus récent. */
    public function exerciceParDefaut(): ?ExerciceComptable
    {
        $jour = today()->toDateString();

        return ExerciceComptable::query()->whereDate('date_debut', '<=', $jour)->whereDate('date_fin', '>=', $jour)->first()
            ?? ExerciceComptable::query()->orderByDesc('date_debut')->first();
    }

    /** Feuille du budget d'un exercice. */
    public function feuille(ExerciceComptable $exercice): array
    {
        $periodes = $exercice->periodes()->get();
        $montants = BudgetComptable::query()->where('exercice_id', $exercice->id)->get(['compte_id', 'periode_id', 'montant']);
        $precedent = ExerciceComptable::query()->whereDate('date_fin', '<', $exercice->date_debut->toDateString())->orderByDesc('date_fin')->first();
        $totauxPrecedent = $precedent
            ? BudgetComptable::query()->where('exercice_id', $precedent->id)->groupBy('compte_id')->selectRaw('compte_id, SUM(montant) as total')->pluck('total', 'compte_id')
            : collect();
        $parCompte = $montants->groupBy('compte_id');
        $lignes = $this->comptes($montants->pluck('compte_id')->unique()->all())->map(function (CompteComptable $compte) use ($parCompte, $totauxPrecedent) {
            $cellules = $parCompte->get($compte->id, collect());

            return [
                'id' => $compte->id,
                'numero' => $compte->numero,
                'libelle' => $compte->libelle,
                'nature' => $compte->nature,
                'actif' => $compte->actif,
                'budgetable' => $compte->budgetable,
                'montants' => $cellules->mapWithKeys(fn (BudgetComptable $c) => [$c->periode_id => round((float) $c->montant, 2)])->all(),
                'total' => round((float) $cellules->sum('montant'), 2),
                'total_precedent' => isset($totauxPrecedent[$compte->id]) ? round((float) $totauxPrecedent[$compte->id], 2) : null,
            ];
        })->values();

        return [
            'exercice' => $this->exercice($exercice),
            'modifiable' => ! $exercice->estCloture(),
            'precedent' => $precedent ? ['id' => $precedent->id, 'libelle' => $precedent->libelle] : null,
            'periodes' => $periodes->map(fn (PeriodeComptable $p) => [
                'id' => $p->id, 'numero' => $p->numero, 'date_debut' => $p->date_debut->toDateString(), 'date_fin' => $p->date_fin->toDateString(), 'fermee' => $p->fermee,
            ])->values()->all(),
            // Objets (et non listes) en JSON : les montants sont indexés par période.
            'comptes' => $lignes->map(fn (array $l) => ['montants' => (object) $l['montants']] + $l)->all(),
            'totaux' => $this->totaux($lignes, $periodes),
        ];
    }

    /**
     * Enregistre des cellules d'un exercice (compte × période), d'un bloc : un montant vide ou nul retire la cellule.
     *
     * @param  list<array{compte_id: int, periode_id: int, montant?: float|string|null}>  $cellules
     */
    public function enregistrer(ExerciceComptable $exercice, array $cellules, ?int $auteur): void
    {
        $this->exigerModifiable($exercice);
        $periodes = $exercice->periodes()->pluck('id')->flip();
        $comptes = CompteComptable::query()->whereIn('id', array_column($cellules, 'compte_id'))->get()->keyBy('id');
        $avecBudget = BudgetComptable::query()->whereIn('compte_id', $comptes->keys())->distinct()->pluck('compte_id')->flip();
        foreach ($cellules as $i => $cellule) {
            $compte = $comptes[$cellule['compte_id']] ?? null;
            $erreur = match (true) {
                ! $compte => __('Compte introuvable.'),
                $compte->type === CompteComptable::EN_TETE => __('Le compte :numero est un en-tête : il ne reçoit pas de budget.', ['numero' => $compte->numero]),
                ! $compte->budgetable && ! isset($avecBudget[$compte->id]) => __("Le compte :numero n'est pas budgétable : ajoutez-le aux comptes du budget.", ['numero' => $compte->numero]),
                ! isset($periodes[$cellule['periode_id']]) => __("Cette période n'appartient pas à l'exercice :exercice.", ['exercice' => $exercice->libelle]),
                default => null,
            };
            if ($erreur) {
                throw ValidationException::withMessages(["cellules.{$i}" => $erreur]);
            }
        }

        DB::transaction(function () use ($exercice, $cellules, $auteur) {
            foreach ($cellules as $cellule) {
                $existant = BudgetComptable::query()->where('compte_id', $cellule['compte_id'])->where('periode_id', $cellule['periode_id'])->first();
                $montant = round((float) ($cellule['montant'] ?? 0), 2);
                if (abs($montant) < 0.005) {
                    $existant?->delete();
                } elseif ($existant) {
                    // Chaque changement passe par le modèle : il est inscrit au journal des modifications.
                    $existant->update(['montant' => $montant, 'modifie_par' => $auteur]);
                } else {
                    BudgetComptable::create([
                        'exercice_id' => $exercice->id, 'compte_id' => $cellule['compte_id'], 'periode_id' => $cellule['periode_id'],
                        'montant' => $montant, 'modifie_par' => $auteur,
                    ]);
                }
            }
        });
    }

    /** Répartit un total d'un compte également entre les périodes de l'exercice (au centime ; le reste sur la dernière). */
    public function repartir(ExerciceComptable $exercice, int $compteId, float $total, ?int $auteur): void
    {
        $periodes = $exercice->periodes()->pluck('id')->all();
        if ($periodes === []) {
            throw new DomainException(__("L'exercice :exercice n'a pas de périodes.", ['exercice' => $exercice->libelle]));
        }
        $parts = $this->parts((int) round($total * 100), count($periodes));
        $this->enregistrer($exercice, array_map(fn (int $periode, int $centimes) => [
            'compte_id' => $compteId, 'periode_id' => $periode, 'montant' => $centimes / 100,
        ], $periodes, $parts), $auteur);
    }

    /**
     * Copie le budget d'un autre exercice dans celui-ci, ajusté d'un pourcentage. Chaque période source va aux périodes
     * de la cible qui couvrent les mêmes dates, décalées du nombre de mois entre les débuts d'exercice : un trimestre se
     * répartit sur ses trois mois, des mois s'additionnent dans leur trimestre. Remplacer : les cellules de la cible sont
     * écrasées (une cellule copiée nulle retire la sienne) ; sinon seules les vides sont remplies.
     *
     * @return array{cellules: int, comptes: int, ignorees: int}
     */
    public function copier(ExerciceComptable $source, ExerciceComptable $cible, float $pourcentage, bool $remplacer, ?int $auteur): array
    {
        $this->exigerModifiable($cible);
        if ($source->id === $cible->id) {
            throw new DomainException(__('Choisissez un autre exercice que celui du budget.'));
        }
        $mois = ($cible->date_debut->year - $source->date_debut->year) * 12 + $cible->date_debut->month - $source->date_debut->month;
        $periodesCible = $cible->periodes()->get();
        $facteur = 1 + $pourcentage / 100;
        $centimes = [];
        $ignorees = 0;
        $parPeriode = BudgetComptable::query()->where('exercice_id', $source->id)->with('periode')->get()->groupBy('periode_id');
        foreach ($parPeriode as $lignes) {
            $periode = $lignes->first()->periode;
            $debut = $periode->date_debut->copy()->addMonthsNoOverflow($mois);
            $fin = $periode->date_fin->copy()->addMonthsNoOverflow($mois);
            $cibles = $periodesCible->filter(fn (PeriodeComptable $p) => $p->date_debut->lte($fin) && $p->date_fin->gte($debut))->pluck('id')->values()->all();
            if ($cibles === []) {
                $ignorees += $lignes->count();

                continue;
            }
            foreach ($lignes as $ligne) {
                $parts = $this->parts((int) round((float) $ligne->montant * $facteur * 100), count($cibles));
                foreach ($cibles as $k => $periodeId) {
                    $centimes[$ligne->compte_id][$periodeId] = ($centimes[$ligne->compte_id][$periodeId] ?? 0) + $parts[$k];
                }
            }
        }

        $existants = BudgetComptable::query()->where('exercice_id', $cible->id)->get(['id', 'compte_id', 'periode_id'])
            ->keyBy(fn (BudgetComptable $b) => "{$b->compte_id}-{$b->periode_id}");
        $lignes = [];
        $retirees = [];
        foreach ($centimes as $compteId => $parPeriodeCible) {
            foreach ($parPeriodeCible as $periodeId => $valeur) {
                $existant = $existants["{$compteId}-{$periodeId}"] ?? null;
                if ($existant && ! $remplacer) {
                    continue;
                }
                if ($valeur === 0) {
                    if ($existant) {
                        $retirees[] = $existant->id;
                    }

                    continue;
                }
                $lignes[] = [
                    'tenant_id' => $cible->tenant_id, 'exercice_id' => $cible->id, 'compte_id' => $compteId, 'periode_id' => $periodeId,
                    'montant' => $valeur / 100, 'modifie_par' => $auteur, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        // D'un bloc, sans une ligne de journal par cellule : la copie y est inscrite une fois.
        DB::transaction(function () use ($lignes, $retirees) {
            BudgetComptable::query()->whereIn('id', $retirees)->delete();
            foreach (array_chunk($lignes, 500) as $paquet) {
                BudgetComptable::query()->upsert($paquet, ['tenant_id', 'compte_id', 'periode_id'], ['exercice_id', 'montant', 'modifie_par', 'updated_at']);
            }
        });
        $resultat = ['cellules' => count($lignes) + count($retirees), 'comptes' => count($centimes), 'ignorees' => $ignorees];
        AuditLog::log('update', 'Budget', $cible->id, null, [
            'copie_de' => $source->libelle, 'pourcentage' => $pourcentage, 'remplacer' => $remplacer, 'cellules' => $resultat['cellules'],
        ], $auteur ? (string) $auteur : null);

        return $resultat;
    }

    /** Ajoute des comptes de détail au budget, ou les en retire (leurs montants restent visibles tant qu'ils existent). */
    public function marquerComptes(array $ids, bool $budgetable): int
    {
        return CompteComptable::query()->whereIn('id', $ids)->where('type', '!=', CompteComptable::EN_TETE)->update(['budgetable' => $budgetable]);
    }

    /**
     * Budget et réel d'un exercice, de la première à la dernière période choisies (par défaut tout l'exercice).
     */
    public function comparaison(ExerciceComptable $exercice, ?int $periodeDu, ?int $periodeAu): array
    {
        $periodes = $exercice->periodes()->get();
        if ($periodes->isEmpty()) {
            throw new DomainException(__("L'exercice :exercice n'a pas de périodes.", ['exercice' => $exercice->libelle]));
        }
        $debut = $periodeDu ? $periodes->firstWhere('id', $periodeDu) : $periodes->first();
        $fin = $periodeAu ? $periodes->firstWhere('id', $periodeAu) : $periodes->last();
        if (! $debut || ! $fin) {
            throw ValidationException::withMessages(['periode_du' => __("Choisissez des périodes de l'exercice :exercice.", ['exercice' => $exercice->libelle])]);
        }
        if ($debut->date_debut->gt($fin->date_debut)) {
            throw ValidationException::withMessages(['periode_au' => __('La dernière période ne peut pas précéder la première.')]);
        }
        $selection = $periodes->filter(fn (PeriodeComptable $p) => $p->date_debut->gte($debut->date_debut) && $p->date_debut->lte($fin->date_debut))->pluck('id');
        $budgets = BudgetComptable::query()->whereIn('periode_id', $selection)->groupBy('compte_id')->selectRaw('compte_id, SUM(montant) as total')->pluck('total', 'compte_id');
        $du = $debut->date_debut->toDateString();
        $au = $fin->date_fin->toDateString();
        $mouvements = $this->soldes->mouvements($du, $au, null, [EcritureComptable::CLOTURE]);
        $comptes = $this->comptes($budgets->keys()->all());

        $lignes = $comptes->map(function (CompteComptable $compte) use ($budgets, $mouvements) {
            $budget = round((float) ($budgets[$compte->id] ?? 0), 2);
            $reel = $this->reel($compte, $mouvements[$compte->id] ?? null);

            return [
                'id' => $compte->id,
                'numero' => $compte->numero,
                'libelle' => $compte->libelle,
                'nature' => $compte->nature,
                'budget' => $budget,
                'reel' => $reel,
                'ecart' => round($reel - $budget, 2),
                'taux' => abs($budget) >= 0.005 ? round($reel / $budget * 100, 1) : null,
                // Une charge sous son budget, un produit au-dessus : favorable.
                'favorable' => match ($compte->nature) {
                    'charge' => $reel <= $budget + 0.004,
                    'produit' => $reel >= $budget - 0.004,
                    default => null,
                },
            ];
        })->values();

        // Produits et charges réels des comptes hors budget : le résultat réel reste celui du compte de résultat.
        $dansLeBudget = $comptes->pluck('id')->flip();
        $horsBudget = ['produits' => 0.0, 'charges' => 0.0];
        foreach (CompteComptable::query()->whereIn('nature', ['produit', 'charge'])->whereIn('id', $mouvements->keys())->get(['id', 'nature']) as $compte) {
            if (! isset($dansLeBudget[$compte->id])) {
                $horsBudget[$compte->nature === 'produit' ? 'produits' : 'charges'] += $this->reel($compte, $mouvements[$compte->id]);
            }
        }
        $horsBudget = array_map(fn (float $v) => round($v, 2), $horsBudget);
        $somme = fn (string $nature, string $champ) => round((float) $lignes->where('nature', $nature)->sum($champ), 2);
        $total = fn (float $budget, float $reel) => ['budget' => round($budget, 2), 'reel' => round($reel, 2), 'ecart' => round($reel - $budget, 2)];
        $produits = $total($somme('produit', 'budget'), $somme('produit', 'reel') + $horsBudget['produits']);
        $charges = $total($somme('charge', 'budget'), $somme('charge', 'reel') + $horsBudget['charges']);

        return [
            'exercice' => $this->exercice($exercice),
            'periode_du' => $debut->id,
            'periode_au' => $fin->id,
            'du' => $du,
            'au' => $au,
            'lignes' => $lignes->all(),
            'hors_budget' => $horsBudget,
            'totaux' => [
                'produits' => $produits,
                'charges' => $charges,
                'resultat' => $total($produits['budget'] - $charges['budget'], $produits['reel'] - $charges['reel']),
            ],
        ];
    }

    /** Comptes de la feuille : les comptes de détail budgétables, et ceux qui ont déjà un budget. */
    private function comptes(array $avecBudget): Collection
    {
        return CompteComptable::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('budgetable', true)->where('type', '!=', CompteComptable::EN_TETE))->orWhereIn('id', $avecBudget))
            ->orderBy('numero')->get(['id', 'numero', 'libelle', 'nature', 'type', 'actif', 'budgetable']);
    }

    /** Produits, charges et résultat budgétés, pour l'exercice et par période. */
    private function totaux(Collection $lignes, Collection $periodes): array
    {
        $somme = fn (string $nature, ?int $periode = null) => round((float) $lignes->where('nature', $nature)
            ->sum(fn (array $l) => $periode === null ? $l['total'] : ($l['montants'][$periode] ?? 0)), 2);
        $parPeriode = [];
        foreach ($periodes as $periode) {
            $produits = $somme('produit', $periode->id);
            $charges = $somme('charge', $periode->id);
            $parPeriode[$periode->id] = ['produits' => $produits, 'charges' => $charges, 'resultat' => round($produits - $charges, 2)];
        }

        return [
            'produits' => $somme('produit'),
            'charges' => $somme('charge'),
            'resultat' => round($somme('produit') - $somme('charge'), 2),
            'par_periode' => (object) $parPeriode,
        ];
    }

    /** Mouvement d'un compte dans son sens naturel : débit − crédit pour un actif ou une charge, l'inverse sinon. */
    private function reel(CompteComptable $compte, ?object $mouvement): float
    {
        if (! $mouvement) {
            return 0.0;
        }
        $solde = $mouvement->debit - $mouvement->credit;

        return round(in_array($compte->nature, self::NATURES_DEBIT, true) ? $solde : -$solde, 2);
    }

    /** Un montant en centimes réparti en parts égales, le reste sur la dernière. */
    private function parts(int $centimes, int $nombre): array
    {
        $part = intdiv($centimes, $nombre);

        return [...array_fill(0, $nombre - 1, $part), $centimes - $part * ($nombre - 1)];
    }

    private function exercice(ExerciceComptable $exercice): array
    {
        return [
            'id' => $exercice->id, 'libelle' => $exercice->libelle, 'statut' => $exercice->statut,
            'date_debut' => $exercice->date_debut->toDateString(), 'date_fin' => $exercice->date_fin->toDateString(),
        ];
    }

    private function exigerModifiable(ExerciceComptable $exercice): void
    {
        if ($exercice->estCloture()) {
            throw new DomainException(__("L'exercice :exercice est clôturé : son budget ne se modifie plus.", ['exercice' => $exercice->libelle]));
        }
    }
}
