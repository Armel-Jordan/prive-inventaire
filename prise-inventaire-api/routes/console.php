<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// File d'envoi des courriels : en attente et relances (actif dès que le planificateur tourne).
Schedule::command('courriels:relancer')->everyFiveMinutes()->withoutOverlapping();
