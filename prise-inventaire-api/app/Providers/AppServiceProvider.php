<?php

namespace App\Providers;

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
    }
}
