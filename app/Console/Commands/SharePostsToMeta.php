<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\MetaPublisher;
use Illuminate\Console\Command;

class SharePostsToMeta extends Command
{
    protected $signature = 'posts:share-meta
        {posts* : Post ids or slugs}
        {--force : Share again even if the post already has a Facebook post}';

    protected $description = 'Share posts on the Facebook Page (writes meta_* columns in the database .env points at)';

    public function handle(MetaPublisher $meta): int
    {
        $posts = Post::query()
            ->where(fn ($q) => $q->whereIn('id', array_filter($this->argument('posts'), 'ctype_digit'))
                ->orWhereIn('slug', $this->argument('posts')))
            ->orderBy('id')
            ->get();

        $failed = 0;
        foreach ($posts as $post) {
            $result = $meta->share($post, $this->option('force'));
            $failed += $result['status'] === 'failed' ? 1 : 0;
            $this->line("#{$post->id} {$post->title}: {$result['status']} - {$result['message']}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
