<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\InstagramPublisher;
use Illuminate\Console\Command;

class PublishDueToInstagram extends Command
{
    protected $signature = 'instagram:publish-due {--limit=3 : Most posts per run}';

    protected $description = 'Post queued articles whose publication time has passed on Instagram (runs every minute from the scheduler)';

    public function handle(InstagramPublisher $instagram): int
    {
        if (! InstagramPublisher::configured()) {
            return self::SUCCESS;
        }

        $due = Post::where('ig_status', 'pending')->published()->oldest('published_at')->limit((int) $this->option('limit'))->get();
        foreach ($due as $post) {
            $result = $instagram->publish($post);
            $this->line("#{$post->id} {$post->title}: {$result['status']} - {$result['message']}");
        }

        return self::SUCCESS;
    }
}
