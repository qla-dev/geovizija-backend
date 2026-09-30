<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\PostContentGenerator;
use App\Services\PostImageGenerator;
use App\Services\QuizGenerator;

/**
 * Read-only context for the Claude content agent (agents/README.md): what exists already, so it
 * can pick fresh topics and avoid repeating articles or quiz questions.
 */
class AgentController extends Controller
{
    public function context()
    {
        $today = QuizGenerator::today();

        return response()->json([
            'today' => $today,
            'timezone' => QuizGenerator::TIMEZONE,
            'defaultAuthor' => PostController::DEFAULT_AUTHOR,
            'hasQuizToday' => Quiz::whereDate('date', $today)->exists(),
            'categories' => Category::query()
                ->withCount('posts')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $c) => ['slug' => $c->slug, 'name' => $c->name, 'posts' => $c->posts_count]),
            'recentPosts' => Post::query()
                ->with('category')
                ->latest('published_at')
                ->limit(60)
                ->get()
                ->map(fn (Post $p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'title' => $p->title,
                    'category' => $p->category?->slug,
                    'publishedAt' => $p->published_at?->toDateString(),
                    'words' => PostContentGenerator::words(PostContentGenerator::textOnly((string) $p->content)),
                    'hasCover' => PostImageGenerator::isGenerated($p->image_url),
                    'pendingInlineImages' => PostImageGenerator::pendingInline($p),
                ]),
            'recentQuizQuestions' => QuizQuestion::query()->latest('id')->limit(150)->pluck('question'),
        ]);
    }
}
