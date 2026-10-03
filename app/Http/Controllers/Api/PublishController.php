<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Quiz;
use App\Models\Suggestion;
use App\Services\InstagramPublisher;
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
 * Publishes a Claude-written article or quiz pasted as JSON (frontend /objavi or any HTTP client).
 * The JSON itself carries the publish secret; without the right one nothing is stored.
 *
 *   {"secret": "...", "type": "article", "category": "priroda", "title": "...", "excerpt": "...", "content": "...", "publishedAt"?: "ISO 8601"}
 *   {"secret": "...", "type": "article", "id": 12, ...only the fields to change...}  (edits an existing article)
 *   {"secret": "...", "type": "quiz", "date"?: "Y-m-d", "title": "...", "intro": "...", "questions": [...]}
 *   {"secret": "...", "type": "suggestions", "used"?: [ids]}  (topic ideas from the admin panel; "used" marks them done)
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
            'suggestions' => $this->suggestions($request),
            default => response()->json(['message' => 'Polje "type" mora biti "article", "quiz" ili "suggestions".'], 422),
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
            // Real photographs only ("data:image/...;base64,..." or https link); nothing is drawn by AI.
            // Without a usable cover the article is saved as a draft; a "[[SLIKA: ...]]" marker without
            // a photo is removed from the body.
            'cover' => ['nullable', 'string'],
            'inlineImages' => ['nullable', 'array', 'max:2'],
            'inlineImages.*' => ['nullable', 'string'],
            // false keeps the article off the Facebook Page (default: shared once images are done).
            'shareToMeta' => ['nullable', 'boolean'],
            // false keeps it off Instagram (default: posted there at publishedAt by instagram:publish-due).
            'shareToInstagram' => ['nullable', 'boolean'],
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
            // A draft until the cover is stored, so the article is never visible without it.
            'published_at' => null,
        ]);

        // Downloading and processing the photos can take a while; keep going if the client gives up.
        ignore_user_abort(true);
        set_time_limit(300);

        $warnings = [];
        $sources = ['agent' => 0];

        $coverError = empty($data['cover']) ? 'nije poslana' : null;
        if ($coverError === null) {
            try {
                $images->generate($post, $data['cover']);
                $sources['agent']++;
            } catch (Throwable $exception) {
                $coverError = 'odbijena: '.$exception->getMessage();
            }
        }

        $this->placeInline($post, $images, $data['inlineImages'] ?? [], $sources, $warnings);

        // No cover: the article stays a draft (not on the site, Facebook or Instagram). Send the cover
        // and publishedAt later with {"id": ...} to publish it.
        if ($coverError !== null) {
            return response()->json([
                'message' => "Naslovna slika {$coverError} — članak NIJE objavljen, spremljen je kao draft (id {$post->id}).",
                'status' => 'draft',
                'scheduled' => false,
                'id' => $post->id,
                'warnings' => $warnings,
                'images' => $sources,
                'facebook' => ['status' => 'skipped', 'message' => 'Draft bez naslovne slike.'],
                'instagram' => ['status' => 'skipped', 'message' => 'Draft bez naslovne slike.'],
                'data' => new PostResource($post->refresh()->load('category')),
            ], 202);
        }

        $post->update(['published_at' => $publishAt]);

        // After the images, so the Facebook preview has the cover. Never fails the publish.
        $facebook = ($data['shareToMeta'] ?? true)
            ? app(MetaPublisher::class)->share($post->refresh())
            : ['status' => 'skipped', 'message' => 'shareToMeta: false'];
        InstagramPublisher::queue($post, $data['shareToInstagram'] ?? true);
        $instagram = $post->ig_status === 'pending'
            ? ['status' => 'pending', 'message' => 'Ide na Instagram u vrijeme objave.']
            : ['status' => 'skipped', 'message' => InstagramPublisher::configured() ? 'shareToInstagram: false' : 'Instagram nije povezan.'];

        return response()->json([
            'message' => $publishAt->isFuture()
                ? 'Članak je zakazan za '.$publishAt->copy()->setTimezone(QuizGenerator::TIMEZONE)->format('d.m.Y. H:i').' (Sarajevo).'
                : 'Članak je objavljen.',
            'status' => $publishAt->isFuture() ? 'scheduled' : 'published',
            'publishedAt' => $publishAt->toIso8601String(),
            'scheduled' => $publishAt->isFuture(),
            'url' => MetaPublisher::articleUrl($post),
            'warnings' => $warnings,
            'images' => $sources,
            'facebook' => $facebook,
            'instagram' => $instagram,
            'data' => new PostResource($post->refresh()->load('category')),
        ], 201);
    }

    /**
     * Edits an existing article; only the fields that are sent change (slug and URL stay).
     *   cover: data URL / https link of a real photograph
     *   content: new body; its new "[[SLIKA: ...]]" markers are filled from inlineImages in order, the rest removed
     *   replaceInline: [{"number": 1, "image": "...", "description"?: "..."}] replaces existing in-text images
     *   publishedAt: applied only when the article has a cover; publishing a draft shares it like a new article
     * A supplied image that is rejected leaves the old one in place and a warning says so. Nothing is drawn by AI.
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
            'cover' => ['sometimes', 'nullable', 'string'],
            'inlineImages' => ['sometimes', 'nullable', 'array', 'max:2'],
            'inlineImages.*' => ['nullable', 'string'],
            'replaceInline' => ['sometimes', 'array', 'max:2'],
            'replaceInline.*.number' => ['required', 'integer', 'min:1'],
            'replaceInline.*.image' => ['required', 'string'],
            'replaceInline.*.description' => ['nullable', 'string', 'max:500'],
            // Only used when this edit publishes a draft (see above).
            'shareToMeta' => ['nullable', 'boolean'],
            'shareToInstagram' => ['nullable', 'boolean'],
        ]);
        $post = Post::findOrFail($data['id']);

        $fields = array_intersect_key($data, array_flip(['title', 'excerpt', 'author']));
        if (isset($data['category'])) {
            $fields['category_id'] = Category::where('slug', $data['category'])->value('id');
        }
        // Applied after the images: a post is published only once it has its cover.
        $publishAt = isset($data['publishedAt']) || isset($data['published_at'])
            ? Carbon::parse($data['publishedAt'] ?? $data['published_at'])
            : null;
        $wasDraft = $post->published_at === null;
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

        $changed = array_keys($fields);
        if ($fields) {
            $before = PostImageGenerator::inlinePaths((string) $post->content);
            $post->update($fields);
            PostImageGenerator::pruneInline($before, (string) $post->content);
        }

        ignore_user_abort(true);
        set_time_limit(300);

        $warnings = [];
        $sources = ['agent' => 0];
        $attempt = function (callable $store, string $label) use (&$warnings, &$sources, &$changed) {
            try {
                $store();
                $sources['agent']++;
                $changed[] = $label;
            } catch (Throwable $exception) {
                $warnings[] = "{$label} nije promijenjena: ".$exception->getMessage();
            }
        };

        if ($cover !== null) {
            $attempt(fn () => $images->generate($post, $cover), 'Naslovna slika');
        }

        foreach ($data['replaceInline'] ?? [] as $item) {
            $attempt(
                fn () => $images->replaceInline($post->refresh(), $item['number'], $item['image'], $item['description'] ?? null),
                "Slika u tekstu {$item['number']}",
            );
        }

        // New markers in the body: supplied photos in marker order, markers without one removed.
        $this->placeInline($post, $images, $data['inlineImages'] ?? [], $sources, $warnings);

        $facebook = $instagram = null;
        if ($publishAt !== null) {
            if (empty($post->refresh()->image_url)) {
                $warnings[] = 'Članak nema naslovnu sliku — publishedAt nije primijenjen, ostaje draft.';
            } else {
                $post->update(['published_at' => $publishAt]);
                $changed[] = 'publishedAt';

                // A draft published now (e.g. one saved without a cover) is shared then; edits are not re-shared.
                if ($wasDraft) {
                    $facebook = ($data['shareToMeta'] ?? true) && ! $post->meta_post_id
                        ? app(MetaPublisher::class)->share($post)
                        : ['status' => 'skipped', 'message' => 'shareToMeta: false'];
                    if ($post->ig_status === null) {
                        InstagramPublisher::queue($post, $data['shareToInstagram'] ?? true);
                    }
                    $instagram = ['status' => $post->ig_status === 'pending' ? 'pending' : 'skipped'];
                }
            }
        }

        $post->refresh();
        $status = $post->published_at === null ? 'draft' : ($post->published_at->isFuture() ? 'scheduled' : 'published');

        if (! $changed && ! $sources['agent']) {
            return response()->json(['message' => 'Ništa nije promijenjeno.', 'status' => $status, 'warnings' => $warnings], $warnings ? 422 : 200);
        }

        return response()->json(array_filter([
            'message' => 'Članak je ažuriran.',
            'status' => $status,
            'scheduled' => $status === 'scheduled',
            'changed' => $changed,
            'url' => MetaPublisher::articleUrl($post),
            'warnings' => $warnings,
            'images' => $sources,
            'facebook' => $facebook,
            'instagram' => $instagram,
            'data' => new PostResource($post->load('category')),
        ], fn ($value) => $value !== null));
    }

    /**
     * Fills the body's "[[SLIKA: ...]]" markers in order with the supplied photos (a rejected one is
     * skipped and the next photo tried), then removes the markers still left without a photo.
     */
    private function placeInline(Post $post, PostImageGenerator $images, array $supplied, array &$sources, array &$warnings): void
    {
        foreach (array_values(array_filter($supplied)) as $i => $source) {
            if (PostImageGenerator::pendingInline($post->refresh()) === 0) {
                break;
            }
            try {
                $images->generateNextInline($post, $source);
                $sources['agent']++;
            } catch (Throwable $exception) {
                $warnings[] = 'Slika u tekstu '.($i + 1).' odbijena: '.$exception->getMessage();
            }
        }

        if ($dropped = PostImageGenerator::dropPendingInline($post->refresh())) {
            $warnings[] = "Bez fotografije za {$dropped} [[SLIKA]] — uklonjeno iz teksta.";
        }
    }

    /** The admin panel's open topic suggestions for the agent; "used": [ids] marks those as done first. */
    private function suggestions(Request $request)
    {
        $data = $request->validate(['used' => ['nullable', 'array'], 'used.*' => ['integer']]);
        $marked = ($data['used'] ?? []) ? Suggestion::whereIn('id', $data['used'])->whereNull('used_at')->update(['used_at' => now()]) : 0;

        return response()->json([
            'marked' => $marked,
            'suggestions' => Suggestion::whereNull('used_at')->orderBy('for_date')->orderBy('id')->get()->map->toApi(),
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
            'url' => "https://geovizija.com/quiz/{$quiz->date->toDateString()}",
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
