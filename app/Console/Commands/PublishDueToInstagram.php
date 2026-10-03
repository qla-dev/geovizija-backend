<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\InstagramPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishDueToInstagram extends Command
{
    protected $signature = 'instagram:publish-due {--limit=3 : Most posts per run}';

    protected $description = 'Post queued articles whose publication time has passed on Instagram (runs every minute from the scheduler)';

    /** Last run of this command (time, outcome), shown by admin GET /api/instagram/status. */
    public const HEARTBEAT = 'app/instagram-cron.json';

    public function handle(InstagramPublisher $instagram): int
    {
        $run = ['ranAt' => now()->toIso8601String(), 'configured' => InstagramPublisher::configured(), 'due' => 0, 'results' => []];

        try {
            if ($run['configured']) {
                $due = Post::where('ig_status', 'pending')->published()->oldest('published_at')->limit((int) $this->option('limit'))->get();
                $run['due'] = $due->count();
                foreach ($due as $post) {
                    $result = $instagram->publish($post);
                    $line = "#{$post->id} {$post->title}: {$result['status']} - {$result['message']}";
                    $run['results'][] = $line;
                    $this->line($line);
                    Log::channel('instagram')->info($line);
                }
            }
        } catch (Throwable $exception) {
            // E.g. the ig_* columns missing because the migration has not run on the server.
            $run['error'] = $exception->getMessage();
            Log::channel('instagram')->error('instagram:publish-due failed: '.$exception->getMessage());
            $this->error($exception->getMessage());
        }

        File::put(storage_path(self::HEARTBEAT), json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return isset($run['error']) ? self::FAILURE : self::SUCCESS;
    }
}
