<?php

namespace App\Services\Banque;

use App\Models\CompteBancaire;
use App\Models\EcritureComptable;
use App\Models\EcritureComptableLigne;
use App\Models\RapprochementBancaire;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rapprochement bancaire (plan de parité fripv2, phase 4.6 : « Conciliation bancaire ») :
 * - un rapprochement porte sur un compte bancaire relié à son compte comptable et sur un relevé de la banque : sa date
 *   et son solde de fin ; son solde de départ est celui du relevé précédent (0 pour le premier, où l'on pointe aussi les
 *   soldes d'ouverture) ;
 * - on y pointe les lignes du compte comptable datées au plus tard du relevé et pas encore pointées ailleurs, quelle
 *   qu'en soit l'origine (encaissements, déboursés, paiements, transactions bancaires, écritures saisies) ;
 * - solde pointé = départ + entrées pointées − sorties pointées ; écart = solde du relevé − solde pointé ; il se termine
 *   quand l'écart est nul (contrairement à fripv2, qui ne compare jamais au relevé) ;
 * - une ligne pointée ne se modifie plus : l'écriture saisie ne se modifie ni ne se supprime, le document (encaissement,
 *   déboursé, transaction, paiement, acompte) ne s'annule plus, tant que la ligne n'est pas retirée du rapprochement ;
 * - un rapprochement en cours se supprime (ses lignes sont libérées) ; le dernier terminé se rouvre.
 */
class Rapprochements
{
    private const TOLERANCE = 0.005;

    /** Rapprochements d'un compte bancaire, le plus récent d'abord. */
    public function liste(CompteBancaire $compte): array
    {
        return RapprochementBancaire::query()->where('compte_bancaire_id', $compte->id)->with(['auteur:id,nom', 'auteurFin:id,nom'])
            ->orderByDesc('date_releve')->orderByDesc('id')->get()->all();
    }

    /** Commence le rapprochement d'un relevé ; un seul en cours par compte, après le dernier terminé. */
    public function commencer(CompteBancaire $compte, array $donnees, ?int $auteur): RapprochementBancaire
    {
        if (! $compte->compte_comptable_id) {
            throw new DomainException("Reliez d'abord le compte bancaire « {$compte->nom} » à son compte comptable.");
        }

        return DB::transaction(function () use ($compte, $donnees, $auteur) {
            // Verrou sur le compte bancaire : deux rapprochements ne commencent pas en même temps.
            CompteBancaire::query()->whereKey($compte->id)->lockForUpdate()->first();
            $enCours = RapprochementBancaire::query()->where('compte_bancaire_id', $compte->id)->where('statut', RapprochementBancaire::EN_COURS)->first();
            if ($enCours) {
                throw new DomainException("Un rapprochement est déjà en cours pour ce compte (relevé du {$enCours->date_releve->format('d/m/Y')}).");
            }
            $dernier = $this->dernierTermine($compte);
            $date = Carbon::parse($donnees['date_releve'])->toDateString();
            $this->verifierDate($date, $dernier);

            return RapprochementBancaire::create([
                'compte_bancaire_id' => $compte->id,
                'date_releve' => $date,
                'solde_releve' => round((float) $donnees['solde_releve'], 2),
                'solde_depart' => $dernier ? (float) $dernier->solde_releve : 0.0,
                'statut' => RapprochementBancaire::EN_COURS,
                'notes' => trim((string) ($donnees['notes'] ?? '')) ?: null,
                'cree_par' => $auteur,
            ]);
        });
    }

    /** Corrige le relevé d'un rapprochement en cours ; les lignes postérieures à une date avancée sont libérées. */
    public function modifier(RapprochementBancaire $rapprochement, array $donnees): RapprochementBancaire
    {
        $this->exigerEnCours($rapprochement);

        return DB::transaction(function () use ($rapprochement, $donnees) {
            if (array_key_exists('date_releve', $donnees)) {
                $date = Carbon::parse($donnees['date_releve'])->toDateString();
                $this->verifierDate($date, $this->dernierTermine($rapprochement->compteBancaire, $rapprochement->id));
                $rapprochement->date_releve = $date;
                EcritureComptableLigne::query()->where('rapprochement_id', $rapprochement->id)
                    ->whereHas('ecriture', fn ($q) => $q->whereDate('date', '>', $date))->update(['rapprochement_id' => null]);
            }
            if (array_key_exists('solde_releve', $donnees)) {
                $rapprochement->solde_releve = round((float) $donnees['solde_releve'], 2);
            }
            if (array_key_exists('notes', $donnees)) {
                $rapprochement->notes = trim((string) $donnees['notes']) ?: null;
            }
            $rapprochement->save();

            return $rapprochement;
        });
    }

    /**
     * Rapprochement et ses lignes : en cours, les lignes à pointer (datées au plus tard du relevé, libres ou pointées
     * ici) ; terminé, ses lignes pointées et celles qui étaient en circulation à la date du relevé.
     */
    public function detail(RapprochementBancaire $rapprochement): array
    {
        $rapprochement->loadMissing(['compteBancaire.compteComptable:id,numero,libelle', 'auteur:id,nom', 'auteurFin:id,nom']);
        $compteId = $rapprochement->compteBancaire->compte_comptable_id;
        $date = $rapprochement->date_releve->toDateString();
        // Rapprochements postérieurs : leurs lignes étaient encore en circulation à la date de celui-ci.
        $posterieurs = RapprochementBancaire::query()->where('compte_bancaire_id', $rapprochement->compte_bancaire_id)
            ->where('date_releve', '>', $date)->pluck('id')->all();

        $lignes = EcritureComptableLigne::query()
            ->join('ecritures_comptables as e', 'e.id', '=', 'ecritures_comptables_lignes.ecriture_id')
            ->join('journaux_comptables as j', 'j.id', '=', 'e.journal_id')
            ->where('ecritures_comptables_lignes.compte_id', $compteId)
            ->whereDate('e.date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ecritures_comptables_lignes.rapprochement_id')
                ->orWhere('ecritures_comptables_lignes.rapprochement_id', $rapprochement->id)
                ->orWhereIn('ecritures_comptables_lignes.rapprochement_id', $posterieurs))
            ->orderBy('e.date')->orderBy('e.numero')->orderBy('ecritures_comptables_lignes.id')
            ->select([
                'ecritures_comptables_lignes.id', 'ecritures_comptables_lignes.debit', 'ecritures_comptables_lignes.credit',
                'ecritures_comptables_lignes.libelle', 'ecritures_comptables_lignes.rapprochement_id',
                'e.id as ecriture_id', 'e.date', 'e.numero', 'e.libelle as libelle_ecriture', 'e.reference', 'e.source', 'j.code as journal',
            ])->toBase()->get()
            ->map(fn ($l) => [
                'id' => (int) $l->id,
                'ecriture_id' => (int) $l->ecriture_id,
                'date' => Carbon::parse($l->date)->toDateString(),
                'numero' => $l->numero,
                'journal' => $l->journal,
                'libelle' => $l->libelle ?: $l->libelle_ecriture,
                'reference' => $l->reference,
                'source' => $l->source,
                'entree' => round((float) $l->debit, 2),
                'sortie' => round((float) $l->credit, 2),
                'pointee' => (int) $l->rapprochement_id === $rapprochement->id,
            ]);

        return [
            'rapprochement' => $rapprochement,
            'lignes' => $lignes->values()->all(),
            'resume' => $this->resume($rapprochement, $lignes),
        ];
    }

    /** Pointe (ou retire) des lignes du compte comptable dans un rapprochement en cours. */
    public function pointer(RapprochementBancaire $rapprochement, array $ids, bool $pointee): array
    {
        $this->exigerEnCours($rapprochement);
        DB::transaction(function () use ($rapprochement, $ids, $pointee) {
            $rapprochement = RapprochementBancaire::query()->whereKey($rapprochement->id)->lockForUpdate()->firstOrFail();
            $lignes = EcritureComptableLigne::query()->whereIn('id', $ids)
                ->where('compte_id', $rapprochement->compteBancaire->compte_comptable_id)
                ->whereHas('ecriture', fn ($q) => $q->whereDate('date', '<=', $rapprochement->date_releve->toDateString()));
            if ($pointee) {
                $lignes = $lignes->where(fn ($q) => $q->whereNull('rapprochement_id')->orWhere('rapprochement_id', $rapprochement->id));
            } else {
                $lignes = $lignes->where('rapprochement_id', $rapprochement->id);
            }
            if ($lignes->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lignes' => 'Choisissez des mouvements de ce compte, datés au plus tard du relevé et pas déjà pointés ailleurs.']);
            }
            $lignes->update(['rapprochement_id' => $pointee ? $rapprochement->id : null]);
        });

        return $this->detail($rapprochement->fresh());
    }

    /** Termine un rapprochement en cours : l'écart avec le relevé doit être nul. */
    public function terminer(RapprochementBancaire $rapprochement, ?int $auteur): RapprochementBancaire
    {
        $this->exigerEnCours($rapprochement);
        $resume = $this->detail($rapprochement)['resume'];
        if (abs($resume['ecart']) > self::TOLERANCE) {
            throw new DomainException("L'écart avec le relevé doit être nul pour terminer le rapprochement : ".number_format($resume['ecart'], 2, ',', ' ').'.');
        }
        $rapprochement->update(['statut' => RapprochementBancaire::TERMINE, 'termine_le' => now(), 'termine_par' => $auteur]);

        return $rapprochement->fresh(['auteur:id,nom', 'auteurFin:id,nom']);
    }

    /** Rouvre le dernier rapprochement terminé d'un compte (aucun autre en cours). */
    public function rouvrir(RapprochementBancaire $rapprochement): RapprochementBancaire
    {
        if ($rapprochement->statut !== RapprochementBancaire::TERMINE) {
            throw new DomainException("Ce rapprochement n'est pas terminé.");
        }
        $autres = RapprochementBancaire::query()->where('compte_bancaire_id', $rapprochement->compte_bancaire_id)->whereKeyNot($rapprochement->id);
        if ((clone $autres)->where('statut', RapprochementBancaire::EN_COURS)->exists()) {
            throw new DomainException('Un autre rapprochement est en cours pour ce compte : terminez-le ou supprimez-le d\'abord.');
        }
        if ((clone $autres)->where('date_releve', '>', $rapprochement->date_releve->toDateString())->exists()) {
            throw new DomainException('Seul le dernier rapprochement terminé du compte se rouvre.');
        }
        $rapprochement->update(['statut' => RapprochementBancaire::EN_COURS, 'termine_le' => null, 'termine_par' => null]);

        return $rapprochement;
    }

    /** Supprime un rapprochement en cours : ses lignes sont libérées. */
    public function supprimer(RapprochementBancaire $rapprochement): void
    {
        $this->exigerEnCours($rapprochement);
        DB::transaction(function () use ($rapprochement) {
            EcritureComptableLigne::query()->where('rapprochement_id', $rapprochement->id)->update(['rapprochement_id' => null]);
            $rapprochement->delete();
        });
    }

    /**
     * Refuse d'annuler ou de supprimer un document dont l'écriture a une ligne pointée dans un rapprochement bancaire :
     * l'argent est passé par la banque.
     */
    public function verifierDocument(string $source, int $id, string $document): void
    {
        $date = EcritureComptableLigne::query()->whereNotNull('rapprochement_id')
            ->whereHas('ecriture', fn ($q) => $q->where('source', $source)->where('source_id', $id)->whereNull('contrepassation_de'))
            ->join('rapprochements_bancaires as r', 'r.id', '=', 'ecritures_comptables_lignes.rapprochement_id')
            ->value('r.date_releve');
        if ($date) {
            throw new DomainException("{$document} : son mouvement est pointé dans le rapprochement bancaire du ".Carbon::parse($date)->format('d/m/Y').' ; retirez-le d\'abord du rapprochement.');
        }
    }

    /** Écriture qui a une ligne pointée dans un rapprochement bancaire. */
    public function estPointee(EcritureComptable $ecriture): bool
    {
        return $ecriture->lignes()->whereNotNull('rapprochement_id')->exists();
    }

    /**
     * Solde pointé, écart avec le relevé, solde comptable à la date du relevé et mouvements en circulation (non pointés).
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     */
    private function resume(RapprochementBancaire $rapprochement, Collection $lignes): array
    {
        $pointees = $lignes->where('pointee', true);
        $enCirculation = $lignes->where('pointee', false);
        $pointe = round((float) $rapprochement->solde_depart + $pointees->sum('entree') - $pointees->sum('sortie'), 2);
        $comptable = EcritureComptableLigne::query()->where('compte_id', $rapprochement->compteBancaire->compte_comptable_id)
            ->whereHas('ecriture', fn ($q) => $q->whereDate('date', '<=', $rapprochement->date_releve->toDateString()))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS solde')->value('solde');

        return [
            'solde_depart' => round((float) $rapprochement->solde_depart, 2),
            'entrees_pointees' => round($pointees->sum('entree'), 2),
            'sorties_pointees' => round($pointees->sum('sortie'), 2),
            'solde_pointe' => $pointe,
            'solde_releve' => round((float) $rapprochement->solde_releve, 2),
            'ecart' => round((float) $rapprochement->solde_releve - $pointe, 2),
            'entrees_en_circulation' => round($enCirculation->sum('entree'), 2),
            'sorties_en_circulation' => round($enCirculation->sum('sortie'), 2),
            'solde_comptable' => round((float) $comptable, 2),
        ];
    }

    private function dernierTermine(CompteBancaire $compte, ?int $sauf = null): ?RapprochementBancaire
    {
        return RapprochementBancaire::query()->where('compte_bancaire_id', $compte->id)->where('statut', RapprochementBancaire::TERMINE)
            ->when($sauf, fn ($q) => $q->whereKeyNot($sauf))->orderByDesc('date_releve')->orderByDesc('id')->first();
    }

    private function verifierDate(string $date, ?RapprochementBancaire $dernier): void
    {
        if ($dernier && $date <= $dernier->date_releve->toDateString()) {
            throw ValidationException::withMessages(['date_releve' => 'Le relevé doit suivre celui du dernier rapprochement terminé ('.$dernier->date_releve->format('d/m/Y').').']);
        }
    }

    private function exigerEnCours(RapprochementBancaire $rapprochement): void
    {
        if ($rapprochement->statut !== RapprochementBancaire::EN_COURS) {
            throw new DomainException('Ce rapprochement est terminé : rouvrez-le pour le modifier.');
        }
    }
}
