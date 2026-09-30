<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\PostImageGenerator;
use Illuminate\Console\Command;
use Throwable;

class GeneratePostImages extends Command
{
    protected $signature = 'posts:generate-images
        {posts?* : Post ids or slugs (default: every post without a generated image)}
        {--force : Regenerate even if the post already has a generated image}';

    protected $description = 'Generate cover images for posts through OpenRouter';

    public function handle(PostImageGenerator $generator): int
    {
        $query = Post::query()->orderBy('id');

        if ($this->argument('posts')) {
            $query->where(fn ($q) => $q->whereIn('id', array_filter($this->argument('posts'), 'ctype_digit'))
                ->orWhereIn('slug', $this->argument('posts')));
        }

        $posts = $query->get()->filter(fn (Post $post) => $this->option('force') || ! PostImageGenerator::isGenerated($post->image_url));

        if ($posts->isEmpty()) {
            $this->info('Nothing to generate.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($posts as $post) {
            $this->line("#{$post->id} {$post->title}");

            try {
                $this->info('  -> '.$generator->generate($post));
            } catch (Throwable $exception) {
                $failed++;
                $this->error('  failed: '.$exception->getMessage());
            }
        }

        $this->newLine();
        $this->info(($posts->count() - $failed)." generated, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
