<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly backup (needs the scheduler: * * * * * php artisan schedule:run)
Schedule::command('paa:backup')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('auth:clear-resets')->daily();
