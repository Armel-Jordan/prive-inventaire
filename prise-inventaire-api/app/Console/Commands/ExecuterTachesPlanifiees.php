<?php

namespace App\Console\Commands;

use App\Services\Planificateur;
use Illuminate\Console\Command;

/** Exécute les tâches planifiées dues de toutes les entreprises (planifiée chaque minute). */
class ExecuterTachesPlanifiees extends Command
{
    protected $signature = 'taches:executer';

    protected $description = 'Exécute les tâches planifiées arrivées à échéance, pour toutes les entreprises.';

    public function handle(Planificateur $planificateur): int
    {
        $this->info($planificateur->executerTachesDues().' tâche(s) exécutée(s).');

        return self::SUCCESS;
    }
}
