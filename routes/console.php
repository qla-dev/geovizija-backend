<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OpenRouter quiz generation shortly after midnight, only when QUIZ_AUTO_GENERATE is on (needs
// `php artisan schedule:run` in cron). By default the Claude agent writes quizzes (agents/daily-quiz.md).
// Instagram cannot schedule posts: queued articles go out here once their publication time has passed.
Illuminate\Support\Facades\Schedule::command('instagram:publish-due')->everyMinute()->withoutOverlapping(10);

Illuminate\Support\Facades\Schedule::command('quizzes:generate')->dailyAt('00:05')->timezone('Europe/Sarajevo')
    ->when(fn () => (bool) config('services.quiz.auto_generate'));

// Proof that the server cron runs schedule:run at all (admin GET /api/instagram/status shows it).
Illuminate\Support\Facades\Schedule::call(fn () => Illuminate\Support\Facades\File::put(storage_path('app/scheduler-heartbeat'), now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

// Page view IP addresses are kept 30 days (privacy page), then cleared; the counts stay.
Illuminate\Support\Facades\Schedule::call(fn () => Illuminate\Support\Facades\Schema::hasTable('page_views')
    && Illuminate\Support\Facades\DB::table('page_views')->whereNotNull('ip')->where('created_at', '<', now()->subDays(30))->update(['ip' => null]))
    ->dailyAt('03:30')->name('page-views-forget-ip');
