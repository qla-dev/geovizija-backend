<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OpenRouter quiz generation shortly after midnight, only when QUIZ_AUTO_GENERATE is on (needs
// `php artisan schedule:run` in cron). By default the Claude agent writes quizzes (agents/daily-quiz.md).
Illuminate\Support\Facades\Schedule::command('quizzes:generate')->dailyAt('00:05')->timezone('Europe/Sarajevo')
    ->when(fn () => (bool) config('services.quiz.auto_generate'));
