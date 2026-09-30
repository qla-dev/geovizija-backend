<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\PostContentGenerator;
use Illuminate\Console\Command;
use Throwable;

class GeneratePostContent extends Command
{
    protected $signature = 'posts:generate-content {posts* : Post ids or slugs}';

    protected $description = 'Rewrite post excerpts and bodies into full articles through OpenRouter';

    public function handle(PostContentGenerator $generator): int
    {
        $ids = $this->argument('posts');
        $posts = Post::query()
            ->where(fn ($q) => $q->whereIn('id', array_filter($ids, 'ctype_digit'))->orWhereIn('slug', $ids))
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($posts as $post) {
            $this->line("#{$post->id} {$post->title}");

            try {
                $generator->generate($post);
                $this->info('  -> '.PostContentGenerator::words($post->content).' words');
            } catch (Throwable $exception) {
                $failed++;
                $this->error('  failed: '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
