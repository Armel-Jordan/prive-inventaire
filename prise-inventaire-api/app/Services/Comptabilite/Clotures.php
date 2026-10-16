<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\ExerciceComptable;
use App\Models\JournalComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'un exercice : une écriture datée du dernier jour solde les comptes de produits et de charges et
 * reporte le résultat (bénéfice ou perte) sur le compte de type « résultat » ; toutes les périodes sont fermées.
 * L'exercice suivant repart ainsi avec des comptes de résultat à zéro. La réouverture supprime cette écriture.
 */
class Clotures
{
    public function __construct(private Ecritures $ecritures, private Soldes $soldes) {}

    /** Résultat de l'exercice, compte qui le reçoit, et ce qui empêche la clôture. */
    public function apercu(ExerciceComptable $exercice): array
    {
        $soldes = $this->soldesResultat($exercice);
        $total = fn (string $nature) => array_sum(array_column(array_filter($soldes, fn (array $s) => $s['nature'] === $nature), 'solde'));
        // Produits au crédit (solde négatif), charges au débit.
        $produits = round(-$total('produit'), 2);
        $charges = round($total('charge'), 2);
        $compte = CompteComptable::where('type', CompteComptable::RESULTAT)->first();

        return [
            'produits' => $produits,
            'charges' => $charges,
            'resultat' => round($produits - $charges, 2),
            'comptes_soldes' => count($soldes),
            'compte' => $compte?->only(['id', 'numero', 'libelle']),
            'bloquant' => $this->bloquant($exercice, $compte, $soldes),
        ];
    }

    /** @throws ValidationException */
    public function cloturer(ExerciceComptable $exercice, ?int $auteur): ExerciceComptable
    {
        $apercu = $this->apercu($exercice);
        if ($apercu['bloquant']) {
            throw ValidationException::withMessages(['exercice' => $apercu['bloquant']]);
        }

        DB::transaction(function () use ($exercice, $auteur, $apercu) {
            $soldes = $this->soldesResultat($exercice);
            if ($soldes) {
                // Chaque compte de résultat est soldé ; la différence va au compte de résultat de l'exercice.
                $lignes = array_map(fn (array $s) => [
                    'compte_id' => $s['compte_id'],
                    'libelle' => null,
                    'debit' => $s['solde'] < 0 ? -$s['solde'] : 0,
                    'credit' => $s['solde'] > 0 ? $s['solde'] : 0,
                ], array_values($soldes));
                if ($apercu['resultat'] != 0) {
                    $lignes[] = [
                        'compte_id' => $apercu['compte']['id'],
                        'libelle' => $apercu['resultat'] > 0 ? 'Bénéfice de l\'exercice' : 'Perte de l\'exercice',
                        'debit' => $apercu['resultat'] < 0 ? -$apercu['resultat'] : 0,
                        'credit' => $apercu['resultat'] > 0 ? $apercu['resultat'] : 0,
                    ];
                }
                $this->ecritures->enregistrer([
                    'journal_id' => JournalComptable::duType('general')->id,
                    'date' => $exercice->date_fin->toDateString(),
                    'libelle' => "Clôture de l'exercice {$exercice->libelle}",
                    'lignes' => $lignes,
                ], EcritureComptable::CLOTURE, null, $auteur, null, true);
            }
            $exercice->periodes()->where('fermee', false)->update(['fermee' => true, 'fermee_le' => now(), 'fermee_par' => $auteur]);
            $exercice->update(['statut' => ExerciceComptable::CLOTURE, 'cloture_le' => now(), 'cloture_par' => $auteur]);
        });

        return $exercice->fresh('periodes');
    }

    /**
     * Rouvre un exercice clôturé : son écriture de clôture est supprimée ; ses périodes restent fermées et se
     * rouvrent une à une. Un exercice suivant déjà clôturé doit être rouvert d'abord.
     *
     * @throws ValidationException
     */
    public function rouvrir(ExerciceComptable $exercice): ExerciceComptable
    {
        if (! $exercice->estCloture()) {
            throw ValidationException::withMessages(['exercice' => 'Cet exercice n\'est pas clôturé.']);
        }
        $suivant = ExerciceComptable::whereDate('date_debut', '>', $exercice->date_fin->toDateString())
            ->where('statut', ExerciceComptable::CLOTURE)->orderBy('date_debut')->first();
        if ($suivant) {
            throw ValidationException::withMessages(['exercice' => "Rouvrez d'abord l'exercice {$suivant->libelle}, clôturé lui aussi."]);
        }

        DB::transaction(function () use ($exercice) {
            EcritureComptable::where('exercice_id', $exercice->id)->where('source', EcritureComptable::CLOTURE)->get()->each->delete();
            $exercice->update(['statut' => ExerciceComptable::OUVERT, 'cloture_le' => null, 'cloture_par' => null]);
        });

        return $exercice->fresh('periodes');
    }

    /**
     * Soldes (débit − crédit) des comptes de produits et de charges au dernier jour de l'exercice. Les exercices
     * précédents étant clôturés, ce sont les soldes de l'exercice lui-même.
     *
     * @return array<int, array{compte_id: int, nature: string, solde: float}>
     */
    private function soldesResultat(ExerciceComptable $exercice): array
    {
        $comptes = CompteComptable::whereIn('nature', ['produit', 'charge'])->orderBy('numero')->get(['id', 'numero', 'nature']);
        $mouvements = $this->soldes->mouvements(null, $exercice->date_fin->toDateString(), $comptes->pluck('id')->all());
        $soldes = [];
        foreach ($comptes as $compte) {
            $solde = round(($mouvements[$compte->id]->debit ?? 0) - ($mouvements[$compte->id]->credit ?? 0), 2);
            if ($solde != 0) {
                $soldes[$compte->id] = ['compte_id' => $compte->id, 'nature' => $compte->nature, 'solde' => $solde];
            }
        }

        return $soldes;
    }

    private function bloquant(ExerciceComptable $exercice, ?CompteComptable $compte, array $soldes): ?string
    {
        if ($exercice->estCloture()) {
            return 'Cet exercice est déjà clôturé.';
        }
        $precedent = ExerciceComptable::whereDate('date_fin', '<', $exercice->date_debut->toDateString())
            ->where('statut', ExerciceComptable::OUVERT)->orderBy('date_debut')->first();
        if ($precedent) {
            return "Clôturez d'abord l'exercice {$precedent->libelle}.";
        }
        if ($soldes && ! $compte) {
            return 'Désignez d\'abord, dans le plan comptable, le compte qui reçoit le résultat (type « Résultat de l\'exercice »).';
        }

        return null;
    }
}
