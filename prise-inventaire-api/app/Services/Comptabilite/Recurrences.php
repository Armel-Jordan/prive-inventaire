<?php

namespace App\Services\Comptabilite;

use App\Models\EcritureComptable;
use App\Models\RecurrenceComptable;
use App\Models\RecurrenceEcheance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écritures récurrentes (plan de parité fripv2, phase 4.2) :
 *
 *  - une écriture manuelle se répète : date de début, nombre de répétitions, fréquence (journalière, hebdomadaire,
 *    tous les 14 jours, mensuelle, trimestrielle, semestrielle, annuelle) ; ses dates sont planifiées d'un coup, et son
 *    journal, son libellé et ses lignes copiés (le modèle peut ensuite changer ou disparaître) ;
 *  - une date échue se convertit en écriture, à la main ou par la tâche planifiée « ecritures_recurrentes » : l'écriture
 *    porte la source « recurrente » et le numéro de sa récurrence ; une date dans une période fermée reste à convertir et
 *    son motif est rapporté ;
 *  - une récurrence se suspend, se reprend ou se supprime (ses écritures déjà créées restent).
 */
class Recurrences
{
    public function __construct(private readonly Ecritures $ecritures) {}

    /** Planifie les répétitions d'une écriture manuelle. */
    public function planifier(EcritureComptable $modele, array $donnees, ?int $auteur): RecurrenceComptable
    {
        if ($modele->source !== EcritureComptable::MANUELLE || $modele->contrepassation_de) {
            throw ValidationException::withMessages(['ecriture' => __('Seule une écriture saisie à la main se répète.')]);
        }
        $modele->loadMissing('lignes');

        return DB::transaction(function () use ($modele, $donnees, $auteur) {
            $numero = (int) RecurrenceComptable::query()->lockForUpdate()->max('numero') + 1;
            $recurrence = RecurrenceComptable::create([
                'tenant_id' => $modele->tenant_id,
                'numero' => $numero,
                'ecriture_modele_id' => $modele->id,
                'journal_id' => $modele->journal_id,
                'libelle' => $modele->libelle,
                'reference' => $modele->reference,
                'lignes' => $modele->lignes->map(fn ($ligne) => [
                    'compte_id' => $ligne->compte_id, 'libelle' => $ligne->libelle, 'debit' => (float) $ligne->debit, 'credit' => (float) $ligne->credit,
                ])->values()->all(),
                'frequence' => $donnees['frequence'],
                'date_debut' => $donnees['date_debut'],
                'nombre' => (int) $donnees['nombre'],
                'active' => true,
                'cree_par' => $auteur,
            ]);
            foreach ($this->dates($donnees['frequence'], Carbon::parse($donnees['date_debut']), (int) $donnees['nombre']) as $date) {
                $recurrence->echeances()->create(['date_prevue' => $date]);
            }

            return $recurrence->load($this->relations());
        });
    }

    /**
     * Dates d'une récurrence : la date de début puis chaque intervalle. Un mois ou une année se compte depuis la date de
     * début (le 31 donne le dernier jour des mois plus courts, sans glisser ensuite).
     *
     * @return list<string>
     */
    public function dates(string $frequence, Carbon $debut, int $nombre): array
    {
        $dates = [];
        for ($rang = 0; $rang < $nombre; $rang++) {
            $date = $debut->copy();
            $dates[] = (match ($frequence) {
                'journaliere' => $date->addDays($rang),
                'hebdomadaire' => $date->addWeeks($rang),
                'quatorze_jours' => $date->addDays(14 * $rang),
                'trimestrielle' => $date->addMonthsNoOverflow(3 * $rang),
                'semestrielle' => $date->addMonthsNoOverflow(6 * $rang),
                'annuelle' => $date->addYearsNoOverflow($rang),
                default => $date->addMonthsNoOverflow($rang),
            })->toDateString();
        }

        return $dates;
    }

    /**
     * Convertit en écritures les dates échues (au plus tard « jusqu'au ») des récurrences actives, ou les dates choisies.
     * Chaque date se convertit seule : une date refusée (période fermée, compte désactivé…) reste à convertir.
     *
     * @param  list<int>|null  $echeances
     * @return array{converties: int, erreurs: list<array{recurrence: int, date: string, message: string}>}
     */
    public function convertir(?array $echeances, Carbon $jusquAu, ?int $auteur): array
    {
        $aConvertir = RecurrenceEcheance::query()->whereNull('ecriture_id')
            ->whereHas('recurrence', fn ($q) => $q->where('active', true))
            ->when($echeances !== null, fn ($q) => $q->whereIn('id', $echeances), fn ($q) => $q->whereDate('date_prevue', '<=', $jusquAu))
            ->with('recurrence')->orderBy('date_prevue')->orderBy('id')->get();
        $converties = 0;
        $erreurs = [];
        foreach ($aConvertir as $echeance) {
            $recurrence = $echeance->recurrence;
            try {
                DB::transaction(function () use ($echeance, $recurrence, $auteur) {
                    // Verrou : la tâche et un clic simultané ne créent pas deux écritures pour la même date.
                    $echeance = RecurrenceEcheance::query()->lockForUpdate()->findOrFail($echeance->id);
                    if ($echeance->ecriture_id) {
                        return;
                    }
                    $ecriture = $this->ecritures->enregistrer([
                        'journal_id' => $recurrence->journal_id,
                        'date' => $echeance->date_prevue->toDateString(),
                        'libelle' => $recurrence->libelle,
                        'reference' => $recurrence->reference,
                        'lignes' => $recurrence->lignes,
                    ], EcritureComptable::RECURRENTE, null, $auteur);
                    $ecriture->update(['source_id' => $recurrence->id]);
                    $echeance->update(['ecriture_id' => $ecriture->id]);
                });
                $converties++;
            } catch (ValidationException $erreur) {
                $erreurs[] = [
                    'recurrence' => $recurrence->numero,
                    'date' => $echeance->date_prevue->toDateString(),
                    'message' => collect($erreur->errors())->flatten()->first() ?? $erreur->getMessage(),
                ];
            }
        }

        return ['converties' => $converties, 'erreurs' => $erreurs];
    }

    /** Relations d'une récurrence affichée. */
    public function relations(): array
    {
        return ['journal:id,code,libelle', 'modele:id,numero', 'auteur:id,nom', 'echeances.ecriture:id,numero,date'];
    }
}
