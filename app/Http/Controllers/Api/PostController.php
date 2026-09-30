<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
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
        $data['read_time'] ??=max(1, (int) ceil(str_word_count(strip_tags($data['content'])) / 200));

        $post = Post::create($data);

        return (new PostResource($post->load('category')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Post $post)
    {
        $post->update($this->validated($request, $post));

        return new PostResource($post->load('category'));
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->noContent();
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
