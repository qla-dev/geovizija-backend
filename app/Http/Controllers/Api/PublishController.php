<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Quiz;
use App\Services\PostContentGenerator;
use App\Services\PostImageGenerator;
use App\Services\QuizGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Publishes a Claude-written article or quiz pasted as JSON (frontend /#/objavi or any HTTP client).
 * The JSON itself carries the publish secret; without the right one nothing is stored.
 *
 *   {"secret": "...", "type": "article", "category": "priroda", "title": "...", "excerpt": "...", "content": "..."}
 *   {"secret": "...", "type": "quiz", "date"?: "Y-m-d", "title": "...", "intro": "...", "questions": [...]}
 */
class PublishController extends Controller
{
    public function __invoke(Request $request, PostImageGenerator $images, QuizGenerator $quizzes)
    {
        $secret = (string) $request->input('secret', '');
        if ($secret === '' || ! Hash::check($secret, (string) config('services.publish.secret_hash'))) {
            return response()->json(['message' => 'Neispravan ili nedostaje "secret" — ništa nije objavljeno.'], 403);
        }

        return match ($request->input('type')) {
            'article' => $this->article($request, $images),
            'quiz' => $this->quiz($request, $quizzes),
            default => response()->json(['message' => 'Polje "type" mora biti "article" ili "quiz".'], 422),
        };
    }

    private function article(Request $request, PostImageGenerator $images)
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::exists('categories', 'slug')],
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'author' => ['nullable', 'string', 'max:255'],
        ]);

        $content = trim(str_replace("\r\n", "\n", $data['content']));
        $words = PostContentGenerator::words(PostContentGenerator::textOnly($content));
        if ($words < 150) {
            return response()->json(['message' => "Tekst je prekratak ({$words} riječi)."], 422);
        }

        $post = Post::create([
            'category_id' => Category::where('slug', $data['category'])->value('id'),
            'slug' => $this->uniqueSlug($data['title']),
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'content' => $content,
            'author' => $data['author'] ?? PostController::DEFAULT_AUTHOR,
            'read_time' => max(1, (int) ceil($words / 200)),
            'featured' => false,
            'published_at' => now(),
        ]);

        // Images take ~20 s each; keep going even if the browser gives up waiting.
        ignore_user_abort(true);
        set_time_limit(300);

        $warnings = [];
        try {
            $images->generate($post);
        } catch (Throwable $exception) {
            $warnings[] = 'Naslovna slika nije generisana: '.$exception->getMessage();
        }

        for ($i = 0; $i < 3 && PostImageGenerator::pendingInline($post->refresh()) > 0; $i++) {
            try {
                $images->generateNextInline($post);
            } catch (Throwable $exception) {
                $warnings[] = 'Slika u tekstu nije generisana: '.$exception->getMessage();
                break;
            }
        }

        return response()->json([
            'message' => 'Članak je objavljen.',
            'url' => "https://geovizija.com/#/article/{$post->id}",
            'warnings' => $warnings,
            'data' => new PostResource($post->refresh()->load('category')),
        ], 201);
    }

    private function quiz(Request $request, QuizGenerator $quizzes)
    {
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:500'],
            'questions' => ['required', 'array', 'min:'.QuizGenerator::QUESTIONS],
        ]);
        $date = $input['date'] ?? QuizGenerator::today();

        if (Quiz::whereDate('date', $date)->exists()) {
            return response()->json(['message' => "Kviz za {$date} već postoji — ništa nije objavljeno."], 409);
        }

        try {
            $quiz = $quizzes->storeExternal($date, $request->only('title', 'intro', 'questions'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Kviz je objavljen.',
            'url' => "https://geovizija.com/#/quiz/{$quiz->date->toDateString()}",
            'warnings' => [],
        ], 201);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'clanak';
        $slug = $base;

        for ($i = 2; Post::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
