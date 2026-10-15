<?php

namespace App\Services;

use App\Models\TacheExecution;
use App\Models\TachePlanifiee;
use App\Support\TachesPlanifiees;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exécution des tâches planifiées de toutes les entreprises : prise en charge atomique (une tâche
 * ne tourne jamais deux fois en même temps), historique de chaque exécution, calcul de l'échéance
 * suivante. Déclenché chaque minute par le planificateur (`taches:executer`) ou, sans planificateur,
 * au passage des requêtes (au plus une fois par minute).
 */
class Planificateur
{
    /** Une exécution restée « en cours » plus longtemps est considérée interrompue. */
    private const DUREE_MAX_MINUTES = 60;

    public const CLE_DERNIER_PASSAGE = 'taches:dernier-passage';

    public function __construct(private readonly TenantContext $contexte) {}

    /** Tâches de l'entreprise courante ; celles qui manquent sont créées, inactives. */
    public function taches(): Collection
    {
        $existantes = TachePlanifiee::all()->keyBy('code');
        foreach (TachesPlanifiees::TACHES as $code => [, $recurrence, $heure]) {
            if (! $existantes->has($code)) {
                $existantes[$code] = TachePlanifiee::create(['code' => $code, 'actif' => false, 'recurrence' => $recurrence, 'heure' => $heure]);
            }
        }

        return collect(TachesPlanifiees::codes())->map(fn (string $code) => $existantes[$code]);
    }

    /** Exécute les tâches dues de toutes les entreprises ; retourne le nombre exécuté. */
    public function executerTachesDues(string $source = 'planificateur'): int
    {
        Cache::put(self::CLE_DERNIER_PASSAGE, ['date' => now()->toIso8601String(), 'source' => $source], now()->addDay());

        $dues = $this->contexte->runWithoutTenant(fn () => TachePlanifiee::query()
            ->where('actif', true)
            ->whereIn('code', TachesPlanifiees::codes())
            ->whereNotNull('prochaine_execution')
            ->where('prochaine_execution', '<=', now())
            ->where(fn ($q) => $this->disponible($q))
            ->orderBy('prochaine_execution')
            ->limit(50)
            ->get());

        $executees = 0;
        foreach ($dues as $tache) {
            if ($this->contexte->runAsTenant((int) $tache->tenant_id, fn () => $this->executer($tache, 'planifie'))) {
                $executees++;
            }
        }

        return $executees;
    }

    /** Sans planificateur : exécution au passage d'une requête, au plus une fois par minute. */
    public function auPassage(): int
    {
        if (! Cache::add('taches:verrou-passage', true, 60)) {
            return 0;
        }

        try {
            return $this->executerTachesDues('requetes');
        } catch (Throwable $erreur) {
            Log::warning('Tâches planifiées au passage : '.$erreur->getMessage());

            return 0;
        }
    }

    /**
     * Exécute une tâche de l'entreprise courante et consigne le résultat. Retourne null si elle
     * tourne déjà. Une exécution manuelle ne décale pas l'échéance planifiée.
     */
    public function executer(TachePlanifiee $tache, string $mode, ?int $declenchePar = null): ?TacheExecution
    {
        $prise = TachePlanifiee::query()->whereKey($tache->id)
            ->where(fn ($q) => $this->disponible($q))
            ->update(['statut' => 'en_cours', 'debut_execution_courante' => now()]);
        if (! $prise) {
            return null;
        }

        $chrono = microtime(true);
        $execution = TacheExecution::create([
            'tache_planifiee_id' => $tache->id, 'code' => $tache->code, 'mode' => $mode, 'debut' => now(), 'declenche_par' => $declenchePar,
        ]);

        try {
            $message = app(TachesPlanifiees::classe($tache->code))->executer();
            $resultat = 'succes';
        } catch (Throwable $erreur) {
            Log::warning("Tâche planifiée {$tache->code} (entreprise {$tache->tenant_id}) en échec : {$erreur->getMessage()}");
            $message = Str::limit($erreur->getMessage(), 2000);
            $resultat = 'erreur';
        }

        $execution->update(['fin' => now(), 'duree_ms' => (int) round((microtime(true) - $chrono) * 1000), 'resultat' => $resultat, 'message' => $message]);

        $tache->refresh();
        $planifiee = $mode === 'planifie';
        $tache->update([
            'statut' => 'en_attente',
            'debut_execution_courante' => null,
            'derniere_execution' => now(),
            'dernier_resultat' => $resultat,
            // Une tâche « une fois » se désactive après son exécution planifiée.
            'actif' => $planifiee && $tache->recurrence === 'une_fois' ? false : $tache->actif,
            'prochaine_execution' => $planifiee ? $this->prochaine($tache, now()) : $tache->prochaine_execution,
        ]);

        return $execution;
    }

    /**
     * Échéance suivante après `$depuis`, selon la récurrence (null pour une tâche « une fois » déjà
     * passée). Les heures s'entendent dans le fuseau des tâches (TACHES_FUSEAU) ; le résultat est
     * rendu dans le fuseau de l'application, celui du stockage.
     */
    public function prochaine(TachePlanifiee $tache, CarbonInterface $depuis): ?CarbonImmutable
    {
        $fuseau = config('taches.fuseau') ?: config('app.timezone');

        return $this->calculerProchaine($tache, CarbonImmutable::instance($depuis)->setTimezone($fuseau))
            ?->setTimezone(config('app.timezone'));
    }

    private function calculerProchaine(TachePlanifiee $tache, CarbonImmutable $depuis): ?CarbonImmutable
    {
        $depuis = $depuis->second(0)->microsecond(0);
        [$heures, $minutes] = array_map('intval', explode(':', $tache->heure ?: '03:00'));

        switch ($tache->recurrence) {
            case 'horaire':
                $date = $depuis->minute($minutes);

                return $date > $depuis ? $date : $date->addHour();

            case 'quotidienne':
                $date = $depuis->setTime($heures, $minutes);

                return $date > $depuis ? $date : $date->addDay();

            case 'hebdomadaire':
                $jours = $tache->jours_semaine ?: [1, 2, 3, 4, 5, 6, 7];
                for ($i = 0; $i <= 7; $i++) {
                    $date = $depuis->addDays($i)->setTime($heures, $minutes);
                    if ($date > $depuis && in_array($date->dayOfWeekIso, $jours, true)) {
                        return $date;
                    }
                }

                return null;

            case 'mensuelle':
                for ($i = 0; $i <= 12; $i++) {
                    $mois = $depuis->startOfMonth()->addMonths($i);
                    // Le 31 d'un mois de 30 jours devient le dernier jour du mois.
                    $date = $mois->day(min($tache->jour_du_mois ?: 1, $mois->daysInMonth))->setTime($heures, $minutes);
                    if ($date > $depuis) {
                        return $date;
                    }
                }

                return null;

            default: // une_fois : la date choisie, tant qu'elle n'est pas passée
                return $tache->prochaine_execution && $tache->prochaine_execution > $depuis
                    ? CarbonImmutable::instance($tache->prochaine_execution)
                    : null;
        }
    }

    /** Libre : en attente, ou « en cours » depuis trop longtemps (exécution interrompue). */
    private function disponible($requete): void
    {
        $requete->where('statut', 'en_attente')
            ->orWhere('debut_execution_courante', '<', now()->subMinutes(self::DUREE_MAX_MINUTES));
    }
}
