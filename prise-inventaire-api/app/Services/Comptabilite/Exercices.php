<?php

namespace App\Services\Comptabilite;

use App\Models\ExerciceComptable;
use App\Models\PeriodeComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Exercices comptables : découpage en périodes, chevauchements, période en cours. */
class Exercices
{
    public const DECOUPAGES = ['mensuel', 'trimestriel', 'unique'];

    /** Durée maximale d'un exercice (un premier exercice peut dépasser douze mois). */
    public const MOIS_MAX = 24;

    /**
     * Périodes d'un exercice : mois civils (le premier et le dernier peuvent être partiels), regroupés par trois
     * pour un découpage trimestriel, ou une seule période.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public function decouper(Carbon $debut, Carbon $fin, string $decoupage): array
    {
        if ($decoupage === 'unique') {
            return [[$debut->copy(), $fin->copy()]];
        }
        $mois = [];
        for ($curseur = $debut->copy(); $curseur->lte($fin); $curseur = $curseur->copy()->addMonthNoOverflow()->startOfMonth()) {
            $finDuMois = $curseur->copy()->endOfMonth()->startOfDay();
            $mois[] = [$curseur->copy(), $finDuMois->gt($fin) ? $fin->copy() : $finDuMois];
        }
        if ($decoupage === 'mensuel') {
            return $mois;
        }

        return array_map(fn (array $groupe) => [$groupe[0][0], end($groupe)[1]], array_chunk($mois, 3));
    }

    /** Message si les dates ne conviennent pas (durée, chevauchement d'un autre exercice), sinon null. */
    public function controlerDates(Carbon $debut, Carbon $fin, ?ExerciceComptable $exercice = null): ?string
    {
        if ($fin->lte($debut)) {
            return __("La fin de l'exercice doit suivre son début.");
        }
        if ($fin->gte($debut->copy()->addMonthsNoOverflow(self::MOIS_MAX))) {
            return __('Un exercice ne peut pas dépasser :mois mois.', ['mois' => self::MOIS_MAX]);
        }
        $chevauche = ExerciceComptable::whereDate('date_debut', '<=', $fin->toDateString())
            ->whereDate('date_fin', '>=', $debut->toDateString())
            ->when($exercice, fn ($q) => $q->where('id', '!=', $exercice->id))
            ->first();

        return $chevauche
            ? __("Ces dates chevauchent l'exercice :libelle (:debut – :fin).", [
                'libelle' => $chevauche->libelle, 'debut' => $chevauche->date_debut->format('d/m/Y'), 'fin' => $chevauche->date_fin->format('d/m/Y'),
            ])
            : null;
    }

    /** Crée ou redécoupe les périodes d'un exercice (ses anciennes périodes sont remplacées). */
    public function genererPeriodes(ExerciceComptable $exercice, string $decoupage): void
    {
        DB::transaction(function () use ($exercice, $decoupage) {
            $exercice->periodes()->delete();
            foreach ($this->decouper($exercice->date_debut, $exercice->date_fin, $decoupage) as $index => [$debut, $fin]) {
                PeriodeComptable::create([
                    'exercice_id' => $exercice->id,
                    'numero' => $index + 1,
                    'date_debut' => $debut->toDateString(),
                    'date_fin' => $fin->toDateString(),
                ]);
            }
        });
    }

    /** Libellé par défaut : « 2026 », ou « 2026-2027 » pour un exercice à cheval sur deux années. */
    public function libelleParDefaut(Carbon $debut, Carbon $fin): string
    {
        return $debut->year === $fin->year ? (string) $debut->year : "{$debut->year}-{$fin->year}";
    }

    /** Découpage actuel d'un exercice, d'après ses périodes. */
    public function decoupageDe(ExerciceComptable $exercice): string
    {
        $nombre = $exercice->relationLoaded('periodes') ? $exercice->periodes->count() : $exercice->periodes()->count();

        return match (true) {
            $nombre <= 1 => 'unique',
            $nombre === count($this->decouper($exercice->date_debut, $exercice->date_fin, 'mensuel')) => 'mensuel',
            default => 'trimestriel',
        };
    }

    /** Période qui contient la date (aujourd'hui par défaut). */
    public function periodeDu(?Carbon $date = null): ?PeriodeComptable
    {
        $jour = ($date ?? today())->toDateString();

        return PeriodeComptable::whereDate('date_debut', '<=', $jour)->whereDate('date_fin', '>=', $jour)->first();
    }
}
