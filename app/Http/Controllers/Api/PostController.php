<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Services\InstagramPublisher;
use App\Services\MetaPublisher;
use App\Services\PostContentGenerator;
use App\Services\PostImageGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class PostController extends Controller
{
    public const DEFAULT_AUTHOR = 'Kulašin';

    /**
     * Published posts, newest first.
     * Filters: ?category=<slug>, ?featured=1, ?search=<text>, ?per_page=<1-100>.
     */
    public function index(Request $request)
    {
        $posts = Post::query()
            ->published()
            ->with('category')
            ->when($request->query('category'), fn ($query, $slug) => $query->whereHas('category', fn ($q) => $q->where('slug', $slug)))
            ->when($request->boolean('featured'), fn ($query) => $query->where('featured', true))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('excerpt', 'like', "%{$search}%"));
            })
            ->latest('published_at')
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 12), 1), 100));

        return PostResource::collection($posts);
    }

    public function show(Post $post)
    {
        abort_unless($post->isPublished(), 404);

        return new PostResource($post->load('category'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] ??= $this->uniqueSlug($data['title']);
        $data['featured'] ??= false;
        $data['author'] ??= self::DEFAULT_AUTHOR;
        $data['read_time'] ??=max(1, (int) ceil(str_word_count(strip_tags($data['content'])) / 200));
        // Never published without a cover: such a post stays a draft until it gets one.
        if (empty($data['image_url'])) {
            $data['published_at'] = null;
        }

        $post = Post::create($data);
        $facebook = app(MetaPublisher::class)->share($post);
        if ($post->published_at) {
            InstagramPublisher::queue($post);
        }

        return (new PostResource($post->load('category')))->additional(['facebook' => $facebook])->response()->setStatusCode(201);
    }

    /** Shares (or with ?force=1 re-shares) an article on the Facebook Page; for retries and older articles. */
    public function shareToMeta(Request $request, Post $post, MetaPublisher $meta)
    {
        $result = $meta->share($post, $request->boolean('force'));

        return response()->json($result, $result['status'] === 'failed' ? 502 : 200);
    }

    /**
     * Posts an article on Instagram now (or with ?force=1 again); for older articles and retries.
     * Not yet published articles are queued instead.
     */
    public function shareToInstagram(Request $request, Post $post, InstagramPublisher $instagram)
    {
        // ?only=story: just the story, e.g. when it failed after the post went out.
        if ($request->query('only') === 'story') {
            $error = InstagramPublisher::configured() ? $instagram->story($post) : 'Instagram nije povezan.';

            return response()->json($error === null
                ? ['status' => 'posted', 'message' => 'Story je objavljen.']
                : ['status' => 'failed', 'message' => $error], $error === null ? 200 : 502);
        }

        $result = $instagram->publish($post, $request->boolean('force'));
        if ($result['status'] === 'pending') {
            InstagramPublisher::queue($post);
        }

        return response()->json($result, $result['status'] === 'failed' ? 502 : 200);
    }

    public function update(Request $request, Post $post)
    {
        $oldImages = PostImageGenerator::inlinePaths((string) $post->content);
        $data = $this->validated($request, $post);

        $cover = array_key_exists('image_url', $data) ? $data['image_url'] : $post->image_url;
        if (! empty($data['published_at']) && empty($cover)) {
            return response()->json(['message' => 'Članak bez naslovne slike ne može biti objavljen — ostaje draft.'], 422);
        }

        $wasScheduled = $post->isScheduled();
        // Back to draft also ends a pause (a paused draft would stay hidden once published again).
        if (array_key_exists('published_at', $data) && $data['published_at'] === null) {
            $post->paused_at = null;
        }
        $post->update($data);
        // ?cover_changed=1: the panel replaced the cover (generate-image) just before this save.
        $edited = $post->wasChanged() || $request->boolean('cover_changed');

        // In-text images the new body no longer references are deleted.
        PostImageGenerator::pruneInline($oldImages, (string) $post->content);

        // A draft that gets its publication date (now or scheduled) is shared then. A scheduled Page post
        // is not public yet, so it follows every edit (replaced; removed when the article goes back to
        // draft). A live one is kept (likes, comments): only its link preview is refreshed.
        $meta = app(MetaPublisher::class);
        $facebook = match (true) {
            $post->wasChanged('published_at') && $post->published_at && ! $post->meta_post_id => $meta->share($post),
            ! $edited || ! $post->meta_post_id => null,
            $wasScheduled && ! $post->published_at => $meta->remove($post),
            $wasScheduled => $meta->share($post, force: true),
            default => null,
        };
        if (! $wasScheduled && $post->meta_post_id && ($post->wasChanged(['title', 'excerpt', 'image_url']) || $request->boolean('cover_changed'))) {
            $meta->refreshPreview($post);
        }
        if ($post->wasChanged('published_at') && $post->published_at && $post->ig_status === null) {
            InstagramPublisher::queue($post);
        }

        return (new PostResource($post->load('category')))->additional(array_filter(['facebook' => $facebook]));
    }

    /**
     * Holds a scheduled article back: it stays "scheduled" even after its time and does not go to
     * Facebook (its scheduled Page post is removed) or Instagram until resumed.
     */
    public function pause(Post $post)
    {
        if (! $post->isScheduled()) {
            return response()->json(['message' => 'Pauzirati se može samo zakazan članak.'], 422);
        }
        $post->forceFill(['paused_at' => $post->paused_at ?? now()])->save();
        $facebook = $post->meta_post_id ? app(MetaPublisher::class)->remove($post) : null;

        return (new PostResource($post->load('category')))->additional(array_filter(['facebook' => $facebook]));
    }

    /**
     * Ends a pause. A time still ahead is scheduled again (Facebook too); one already passed goes live
     * now: Facebook at once, Instagram with the next instagram:publish-due run (every minute).
     */
    public function resume(Post $post)
    {
        if ($post->paused_at === null) {
            return response()->json(['message' => 'Članak nije pauziran.'], 422);
        }
        $post->forceFill(['paused_at' => null])->save();
        $facebook = $post->meta_post_id ? null : app(MetaPublisher::class)->share($post);

        return (new PostResource($post->load('category')))->additional(array_filter(['facebook' => $facebook]));
    }

    /**
     * Brings older images up to toJpeg() (16:9, logo, 100 KB), a few posts per call to stay inside
     * hosting time limits: call with ?after=<next> until `next` is null.
     */
    public function reprocessImages(Request $request, PostImageGenerator $images)
    {
        $limit = min(max((int) $request->query('limit', 3), 1), 10);
        $after = (int) $request->query('after', 0);
        $processed = [];
        $last = null;

        foreach (Post::where('id', '>', $after)->orderBy('id')->lazy() as $post) {
            $last = $post->id;
            $paths = [$post->image_url, ...PostImageGenerator::inlinePaths((string) $post->content)];
            if (collect($paths)->contains(fn ($path) => PostImageGenerator::needsReprocess($path))) {
                $processed[$post->id] = $images->reprocess($post);
                if (count($processed) >= $limit) {
                    break;
                }
            }
        }

        $more = $last !== null && Post::where('id', '>', $last)->exists();

        return response()->json(['processed' => $processed, 'next' => $more ? $last : null]);
    }

    /**
     * Link-preview fields of a published or scheduled article, for the frontend's og.php:
     * Facebook reads the preview when a scheduled Page post is created, before the article is public.
     * Drafts are 404; the body is not included.
     */
    public function preview(Post $post)
    {
        abort_if($post->published_at === null, 404);
        $article = (new PostResource($post->load('category')))->resolve();
        // The Facebook link image (title on the photo) when drawn, else the cover.
        $share = PostImageGenerator::isGenerated($post->image_url) ? PostImageGenerator::sharePath($post->image_url) : null;
        $image = $share && is_file(public_path($share)) ? $share : (PostImageGenerator::isGenerated($post->image_url) ? $post->image_url : null);
        $size = $image ? @getimagesize(public_path($image)) : false;

        return response()->json(['data' => array_intersect_key($article, array_flip(
            ['id', 'slug', 'title', 'excerpt', 'category', 'imageUrl', 'author', 'publishedAt'],
        )) + [
            'shareImageUrl' => $image ? asset($image) : $article['imageUrl'],
            'imageWidth' => $size[0] ?? null,
            'imageHeight' => $size[1] ?? null,
        ]]);
    }

    /**
     * Replaces the cover with the required `image` (data URL or https link of a real photograph;
     * nothing is drawn by AI). On failure the old cover stays.
     */
    public function generateImage(Request $request, Post $post, PostImageGenerator $generator)
    {
        $data = $request->validate(['image' => ['required', 'string']]);

        try {
            $generator->generate($post, $data['image']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return new PostResource($post->refresh()->load('category'));
    }

    /**
     * Stores the required `image` (data URL or https link of a real photograph): without `number`
     * for the next pending "[[SLIKA: ...]]" marker, with `number` in place of that existing in-text
     * image (1-based; optional new caption `description`).
     */
    public function generateInlineImage(Request $request, Post $post, PostImageGenerator $generator)
    {
        $data = $request->validate([
            'image' => ['required', 'string'],
            'number' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $path = isset($data['number'])
                ? $generator->replaceInline($post, $data['number'], $data['image'], $data['description'] ?? null)
                : $generator->generateNextInline($post, $data['image']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'pending' => PostImageGenerator::pendingInline($post)], 422);
        }

        return response()->json(['generated' => $path, 'pending' => PostImageGenerator::pendingInline($post->refresh())]);
    }

    /** Rewrites the excerpt and body into a full article through OpenRouter. */
    public function generateContent(Post $post, PostContentGenerator $generator)
    {
        try {
            $generator->generate($post);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return new PostResource($post->refresh()->load('category'));
    }

    /**
     * Deletes the article and its Facebook and Instagram posts (scheduled or live). The article is
     * deleted even when Meta refuses; the `facebook` / `instagram` results say what happened there.
     */
    public function destroy(Post $post, MetaPublisher $meta, InstagramPublisher $instagram)
    {
        $facebook = $post->meta_post_id ? $meta->remove($post) : null;
        $ig = $post->ig_media_id ? $instagram->remove($post) : null;

        if (PostImageGenerator::isGenerated($post->image_url)) {
            File::delete(public_path($post->image_url));
        }

        $post->delete();

        return response()->json(array_filter(['deleted' => true, 'facebook' => $facebook, 'instagram' => $ig]));
    }

    /** Accepts `category` as a slug and maps it to category_id. */
    private function validated(Request $request, ?Post $post = null): array
    {
        $required = $post ? 'sometimes' : 'required';

        $data = $request->validate([
            'category' => [$required, 'string', Rule::exists('categories', 'slug')],
            'title' => [$required, 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('posts')->ignore($post)],
            'excerpt' => ['nullable', 'string'],
            'content' => [$required, 'string'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'author' => ['nullable', 'string', 'max:255'],
            'read_time' => ['nullable', 'integer', 'min:1', 'max:600'],
            'featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        if (isset($data['content'])) {
            $data['content'] = PostImageGenerator::relativeContent($data['content']);
        }

        if (isset($data['category'])) {
            $data['category_id'] = Category::where('slug', $data['category'])->value('id');
            unset($data['category']);
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;

        for ($i = 2; Post::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
