<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\PublishDueToInstagram;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\InstagramPublisher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Admin diagnostics for the Instagram queue: whether the server cron runs, whether the command
 * works, what is waiting and what failed, with the tail of the logs.
 */
class InstagramController extends Controller
{
    public function status()
    {
        $columns = Schema::hasColumn('posts', 'ig_status');
        $heartbeat = storage_path('app/scheduler-heartbeat');
        $lastRun = storage_path(PublishDueToInstagram::HEARTBEAT);

        return response()->json([
            'now' => now()->toIso8601String(),
            'configured' => InstagramPublisher::configured(),
            // Written every minute by schedule:run; old or null = the server cron is not running it.
            'schedulerLastRun' => File::exists($heartbeat) ? trim(File::get($heartbeat)) : null,
            // Written by every instagram:publish-due run.
            'commandLastRun' => File::exists($lastRun) ? json_decode(File::get($lastRun), true) : null,
            // False = migration 2026_10_02_000003 has not run (php artisan migrate).
            'igColumns' => $columns,
            'counts' => $columns ? Post::query()->toBase()->select('ig_status')->selectRaw('count(*) as n')->groupBy('ig_status')->get()->mapWithKeys(fn ($row) => [$row->ig_status ?? 'none' => (int) $row->n]) : null,
            'dueNow' => $columns ? Post::where('ig_status', 'pending')->published()->count() : null,
            'nextPending' => $columns ? Post::where('ig_status', 'pending')->orderBy('published_at')->limit(5)->get(['id', 'title', 'published_at']) : null,
            'failed' => $columns ? Post::where('ig_status', 'failed')->latest('updated_at')->limit(10)->get(['id', 'title', 'ig_error', 'updated_at']) : null,
            'instagramLog' => self::tail(collect(File::glob(storage_path('logs/instagram*.log')))->sort()->last(), 40),
            'schedulerLog' => self::tail(storage_path('logs/scheduler.log'), 40),
            'laravelLog' => self::tail(storage_path('logs/laravel.log'), 15),
        ]);
    }

    /** Last $lines lines of a log file (null when missing), read from the end so big logs stay cheap. */
    private static function tail(?string $file, int $lines): ?array
    {
        if (! $file || ! is_file($file)) {
            return null;
        }
        $handle = fopen($file, 'r');
        $size = filesize($file);
        fseek($handle, max(0, $size - 20000));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return array_slice(array_map(fn ($line) => mb_substr($line, 0, 400), preg_split('/\r?\n/', trim($text))), -$lines);
    }
}
