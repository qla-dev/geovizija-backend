<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pre-generate the daily quiz shortly after midnight (needs `php artisan schedule:run` in cron).
// Without cron, the first visitor of the day triggers generation through GET /api/quizzes/today.
Illuminate\Support\Facades\Schedule::command('quizzes:generate')->dailyAt('00:05')->timezone('Europe/Sarajevo');
