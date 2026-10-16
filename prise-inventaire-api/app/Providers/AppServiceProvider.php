<?php

namespace App\Providers;

use App\Observers\JournalModifications;
use App\Services\Planificateur;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dev local sans MySQL : rediriger la connexion 'mysql' (forcée en dur par certains
        // modèles) vers le sqlite local afin de pouvoir lancer l'app via `php artisan serve`.
        // Strictement limité à l'environnement local + connexion par défaut sqlite ;
        // production (MySQL) et tests ne sont pas affectés.
        if ($this->app->environment('local') && config('database.default') === 'sqlite') {
            config(['database.connections.mysql' => config('database.connections.sqlite')]);
        }

        // Journal des modifications (écran Historique) des fiches et documents.
        foreach (array_keys(JournalModifications::MODELES) as $modele) {
            $modele::observe(JournalModifications::class);
        }

        // Tâches planifiées des entreprises : échéances vérifiées chaque minute par le planificateur
        // (cron « schedule:run »)…
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('taches:executer')->everyMinute()->withoutOverlapping();
        });

        // … et, sans planificateur, après la réponse d'une requête web (le client n'attend pas),
        // au plus une fois par minute. Désactivable : TACHES_DECLENCHEMENT_REQUETES.
        if (config('taches.declenchement_requetes') && ! $this->app->runningInConsole()) {
            $this->app->terminating(fn () => $this->app->make(Planificateur::class)->auPassage());
        }
    }
}
