<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\Suggestion;
use App\Services\AdminPanel;
use App\Services\QuizGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The admin panel (frontend /admin): login, articles, statistics and topic suggestions. */
class AdminController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        if (! AdminPanel::check($data['username'], $data['password'])) {
            return response()->json(['message' => 'Pogrešno korisničko ime ili lozinka.'], 422);
        }

        return response()->json(['token' => AdminPanel::issue(), 'username' => AdminPanel::USERNAME]);
    }

    /** All articles (drafts and scheduled too), newest first, with view counts. ?search=, ?status=published|scheduled|draft */
    public function posts(Request $request)
    {
        $views = Schema::hasTable('page_views');
        $query = Post::query()->with('category')
            ->when($views, fn ($q) => $q->withCount(['views as views_count']))
            ->when($request->query('search'), fn ($q, $search) => $q->where('title', 'like', "%{$search}%"))
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                'draft' => $q->whereNull('published_at'),
                'scheduled' => $q->where('published_at', '>', now()),
                'published' => $q->where('published_at', '<=', now()),
                default => $q,
            })
            ->orderByRaw('published_at is null desc')->orderByDesc('published_at')->orderByDesc('id');

        $page = $query->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Post $post) => $this->row($post))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function post(Post $post)
    {
        $daily = Schema::hasTable('page_views')
            ? DB::table('page_views')->where('post_id', $post->id)->where('created_at', '>=', now()->subDays(13)->startOfDay())
                ->selectRaw('date(created_at) as day, count(*) as views, count(distinct visitor) as visitors')->groupBy('day')->orderBy('day')->get()
            : [];

        return response()->json([
            'data' => (new PostResource($post->load('category')))->resolve() + $this->row($post->loadCount(Schema::hasTable('page_views') ? ['views as views_count'] : [])),
            'daily' => $daily,
        ]);
    }

    public function stats(Request $request)
    {
        if (! Schema::hasTable('page_views')) {
            return response()->json(['message' => 'Statistika još nije uključena (migracija nije pokrenuta).'], 503);
        }
        $days = min(max((int) $request->query('days', 7), 1), 90);
        $from = now()->subDays($days - 1)->startOfDay();
        $views = fn (Carbon $since) => DB::table('page_views')->where('page_views.created_at', '>=', $since);
        $totals = fn (Carbon $since) => (array) $views($since)->selectRaw('count(*) as views, count(distinct visitor) as visitors')->first();

        return response()->json([
            'days' => $days,
            'live' => [
                'visitors5min' => $views(now()->subMinutes(5))->distinct()->count('visitor'),
                'views30min' => $views(now()->subMinutes(30))->count(),
            ],
            'today' => $totals(now()->startOfDay()),
            'week' => $totals(now()->subDays(6)->startOfDay()),
            'month' => $totals(now()->subDays(29)->startOfDay()),
            'daily' => $views($from)->selectRaw('date(created_at) as day, count(*) as views, count(distinct visitor) as visitors')
                ->groupBy('day')->orderBy('day')->get(),
            'topArticles' => $views($from)->whereNotNull('page_views.post_id')
                ->join('posts', 'posts.id', '=', 'page_views.post_id')
                ->selectRaw('posts.id, posts.title, posts.slug, count(*) as views, count(distinct visitor) as visitors')
                ->groupBy('posts.id', 'posts.title', 'posts.slug')->orderByDesc('views')->limit(15)->get(),
            'topPages' => $views($from)->selectRaw('path, count(*) as views')->groupBy('path')->orderByDesc('views')->limit(10)->get(),
            'referrers' => $views($from)->whereNotNull('referrer')->selectRaw('referrer, count(*) as views')->groupBy('referrer')->orderByDesc('views')->limit(10)->get(),
            'devices' => $views($from)->selectRaw('device, count(*) as views')->groupBy('device')->pluck('views', 'device'),
            'recent' => DB::table('page_views')->leftJoin('posts', 'posts.id', '=', 'page_views.post_id')
                ->select('page_views.path', 'page_views.ip', 'page_views.visitor', 'page_views.device', 'page_views.referrer', 'page_views.created_at', 'posts.title')
                ->orderByDesc('page_views.id')->limit(50)->get(),
        ]);
    }

    public function suggestions()
    {
        if (! Schema::hasTable('suggestions')) {
            return response()->json(['message' => 'Prijedlozi još nisu uključeni (migracija nije pokrenuta).'], 503);
        }

        return response()->json([
            'open' => Suggestion::whereNull('used_at')->orderBy('for_date')->orderByDesc('id')->get()->map->toApi(),
            'used' => Suggestion::whereNotNull('used_at')->latest('used_at')->limit(30)->get()->map->toApi(),
        ]);
    }

    public function storeSuggestion(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'in:typed,voice'],
            'forDate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $suggestion = Suggestion::create([
            'text' => trim($data['text']),
            'source' => $data['source'] ?? 'typed',
            'for_date' => $data['forDate'] ?? now(QuizGenerator::TIMEZONE)->addDay()->toDateString(),
        ]);

        return response()->json(['data' => $suggestion->toApi()], 201);
    }

    public function destroySuggestion(Suggestion $suggestion)
    {
        $suggestion->delete();

        return response()->noContent();
    }

    /**
     * Runs pending migrations (the redeploy does not). ?pretend=1 only lists the SQL. Static
     * ADMIN_API_TOKEN only, not the panel login.
     */
    public function migrate(Request $request)
    {
        if (! hash_equals((string) config('services.admin.token'), (string) $request->bearerToken())) {
            return response()->json(['message' => 'Samo sa ADMIN_API_TOKEN.'], 403);
        }
        Artisan::call('migrate', ['--force' => true, '--pretend' => $request->boolean('pretend')]);

        return response()->json(['output' => Artisan::output()]);
    }

    private function row(Post $post): array
    {
        $status = $post->published_at === null ? 'draft' : ($post->published_at->isFuture() ? 'scheduled' : 'published');

        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'category' => $post->category?->name,
            'imageUrl' => (new PostResource($post))->resolve()['imageUrl'] ?? null,
            'publishedAt' => $post->published_at?->toIso8601String(),
            'status' => $status,
            'views' => (int) ($post->views_count ?? 0),
            'facebook' => $post->meta_post_id ? 'shared' : ($post->meta_error ? 'failed' : null),
            'facebookError' => $post->meta_error,
            'instagram' => $post->ig_status,
            'instagramError' => $post->ig_error,
        ];
    }
}
