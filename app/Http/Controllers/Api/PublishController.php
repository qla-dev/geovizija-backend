<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Quiz;
use App\Services\PostContentGenerator;
use App\Services\MetaPublisher;
use App\Services\PostImageGenerator;
use App\Services\QuizGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Publishes a Claude-written article or quiz pasted as JSON (frontend /#/objavi or any HTTP client).
 * The JSON itself carries the publish secret; without the right one nothing is stored.
 *
 *   {"secret": "...", "type": "article", "category": "priroda", "title": "...", "excerpt": "...", "content": "...", "publishedAt"?: "ISO 8601"}
 *   {"secret": "...", "type": "article", "id": 12, ...only the fields to change...}  (edits an existing article)
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
            'article' => $request->has('id') ? $this->updateArticle($request, $images) : $this->article($request, $images),
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
            // Optional scheduled publication (ISO 8601 with offset, e.g. 2026-10-01T14:20:00+02:00).
            // Until then the article is hidden from the site and from /posts (Post::published()).
            'publishedAt' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            // Optional images supplied by the agent ("data:image/...;base64,..." or https link).
            // Any that are missing or fail are drawn through OpenRouter instead.
            'cover' => ['nullable', 'string'],
            'inlineImages' => ['nullable', 'array', 'max:2'],
            'inlineImages.*' => ['nullable', 'string'],
            // false keeps the article off the Facebook Page (default: shared once images are done).
            'shareToMeta' => ['nullable', 'boolean'],
        ]);
        $publishAt = Carbon::parse($data['publishedAt'] ?? $data['published_at'] ?? 'now');

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
            'published_at' => $publishAt,
        ]);

        // Images take ~20 s each; keep going even if the browser gives up waiting.
        ignore_user_abort(true);
        set_time_limit(300);

        $warnings = [];
        $sources = ['agent' => 0, 'api' => 0];

        // Supplied image first; on failure (or when none was sent) fall back to OpenRouter.
        $place = function (callable $store, ?string $supplied, string $label) use (&$warnings, &$sources) {
            if ($supplied) {
                try {
                    $store($supplied);
                    $sources['agent']++;

                    return;
                } catch (Throwable $exception) {
                    $warnings[] = "{$label}: poslana slika odbijena ({$exception->getMessage()}), generišem preko API-ja.";
                }
            }
            try {
                $store(null);
                $sources['api']++;
            } catch (Throwable $exception) {
                $warnings[] = "{$label} nije generisana: ".$exception->getMessage();
            }
        };

        $place(fn (?string $src) => $images->generate($post, $src), $data['cover'] ?? null, 'Naslovna slika');

        $inline = array_values($data['inlineImages'] ?? []);
        for ($i = 0; $i < 2 && PostImageGenerator::pendingInline($post->refresh()) > 0; $i++) {
            $place(fn (?string $src) => $images->generateNextInline($post, $src), $inline[$i] ?? null, 'Slika u tekstu '.($i + 1));
        }

        // After the images, so the Facebook preview has the cover. Never fails the publish.
        $facebook = ($data['shareToMeta'] ?? true)
            ? app(MetaPublisher::class)->share($post->refresh())
            : ['status' => 'skipped', 'message' => 'shareToMeta: false'];

        return response()->json([
            'message' => $publishAt->isFuture()
                ? 'Članak je zakazan za '.$publishAt->copy()->setTimezone(QuizGenerator::TIMEZONE)->format('d.m.Y. H:i').' (Sarajevo).'
                : 'Članak je objavljen.',
            'publishedAt' => $publishAt->toIso8601String(),
            'scheduled' => $publishAt->isFuture(),
            'url' => "https://geovizija.com/#/article/{$post->id}",
            'warnings' => $warnings,
            'images' => $sources,
            'facebook' => $facebook,
            'data' => new PostResource($post->refresh()->load('category')),
        ], 201);
    }

    /**
     * Edits an existing article; only the fields that are sent change (slug and URL stay).
     *   cover: data URL / https link, or true to redraw through OpenRouter
     *   content: new body; its new "[[SLIKA: ...]]" markers are drawn, filled from inlineImages in order
     *   replaceInline: [{"number": 1, "image"?: "...", "description"?: "..."}] replaces existing in-text images
     * A supplied image that is rejected does not fall back to OpenRouter: the old one stays and a warning says so.
     */
    private function updateArticle(Request $request, PostImageGenerator $images)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', Rule::exists('posts', 'id')],
            'category' => ['sometimes', 'string', Rule::exists('categories', 'slug')],
            'title' => ['sometimes', 'string', 'max:255'],
            'excerpt' => ['sometimes', 'string', 'max:500'],
            'content' => ['sometimes', 'string'],
            'author' => ['sometimes', 'string', 'max:255'],
            'publishedAt' => ['sometimes', 'date'],
            'published_at' => ['sometimes', 'date'],
            'cover' => ['sometimes', 'nullable'],
            'inlineImages' => ['sometimes', 'nullable', 'array', 'max:2'],
            'inlineImages.*' => ['nullable', 'string'],
            'replaceInline' => ['sometimes', 'array', 'max:2'],
            'replaceInline.*.number' => ['required', 'integer', 'min:1'],
            'replaceInline.*.image' => ['nullable', 'string'],
            'replaceInline.*.description' => ['nullable', 'string', 'max:500'],
        ]);
        $post = Post::findOrFail($data['id']);

        $fields = array_intersect_key($data, array_flip(['title', 'excerpt', 'author']));
        if (isset($data['category'])) {
            $fields['category_id'] = Category::where('slug', $data['category'])->value('id');
        }
        if (isset($data['publishedAt']) || isset($data['published_at'])) {
            $fields['published_at'] = Carbon::parse($data['publishedAt'] ?? $data['published_at']);
        }
        if (isset($data['content'])) {
            $content = PostImageGenerator::relativeContent(trim(str_replace("\r\n", "\n", $data['content'])));
            $words = PostContentGenerator::words(PostContentGenerator::textOnly($content));
            if ($words < 150) {
                return response()->json(['message' => "Tekst je prekratak ({$words} riječi)."], 422);
            }
            $fields['content'] = $content;
            $fields['read_time'] = max(1, (int) ceil($words / 200));
        }

        $cover = $data['cover'] ?? null;
        if ($cover !== null && $cover !== true && ! is_string($cover)) {
            return response()->json(['message' => 'Polje "cover" mora biti slika (data:image/... ili https link) ili true.'], 422);
        }

        $changed = array_keys($fields);
        if ($fields) {
            $before = PostImageGenerator::inlinePaths((string) $post->content);
            $post->update($fields);
            PostImageGenerator::pruneInline($before, (string) $post->content);
        }

        ignore_user_abort(true);
        set_time_limit(300);

        $warnings = [];
        $sources = ['agent' => 0, 'api' => 0];
        $attempt = function (callable $store, ?string $supplied, string $label) use (&$warnings, &$sources, &$changed) {
            try {
                $store($supplied);
                $sources[$supplied !== null ? 'agent' : 'api']++;
                $changed[] = $label;
            } catch (Throwable $exception) {
                $warnings[] = "{$label} nije promijenjena: ".$exception->getMessage();
            }
        };

        if ($cover !== null) {
            $attempt(fn (?string $src) => $images->generate($post, $src), is_string($cover) ? $cover : null, 'Naslovna slika');
        }

        foreach ($data['replaceInline'] ?? [] as $item) {
            $attempt(
                fn (?string $src) => $images->replaceInline($post->refresh(), $item['number'], $src, $item['description'] ?? null),
                $item['image'] ?? null,
                "Slika u tekstu {$item['number']}",
            );
        }

        // New markers in the body: supplied images in marker order, otherwise drawn through OpenRouter.
        $inline = array_values($data['inlineImages'] ?? []);
        for ($i = 0; $i < 2 && PostImageGenerator::pendingInline($post->refresh()) > 0; $i++) {
            $supplied = $inline[$i] ?? null;
            if ($supplied !== null) {
                try {
                    $images->generateNextInline($post, $supplied);
                    $sources['agent']++;

                    continue;
                } catch (Throwable $exception) {
                    $warnings[] = 'Nova slika u tekstu '.($i + 1).": poslana slika odbijena ({$exception->getMessage()}), generišem preko API-ja.";
                }
            }
            try {
                $images->generateNextInline($post);
                $sources['api']++;
            } catch (Throwable $exception) {
                $warnings[] = 'Nova slika u tekstu '.($i + 1).' nije generisana: '.$exception->getMessage();
            }
        }

        if (! $changed && ! $sources['agent'] && ! $sources['api']) {
            return response()->json(['message' => 'Ništa nije promijenjeno.', 'warnings' => $warnings], $warnings ? 422 : 200);
        }

        return response()->json([
            'message' => 'Članak je ažuriran.',
            'changed' => $changed,
            'url' => "https://geovizija.com/#/article/{$post->id}",
            'warnings' => $warnings,
            'images' => $sources,
            'data' => new PostResource($post->refresh()->load('category')),
        ]);
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
