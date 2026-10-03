<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Page view beacon: the frontend sends {"p": path, "r": referrer, "v": browser id} on every route
 * change with navigator.sendBeacon (text/plain, so no CORS preflight). Bots are ignored. Kept: a hash
 * of the random browser id, the IP address (cleared after 30 days), path, referrer host and device.
 */
class TrackController extends Controller
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|facebot|meta-externalagent|whatsapp|telegram|preview|headless|lighthouse|curl|wget|python|php|java\//i';

    public function store(Request $request)
    {
        $agent = (string) $request->userAgent();
        $data = json_decode((string) $request->getContent(), true);
        if (! is_array($data) || $agent === '' || preg_match(self::BOTS, $agent)) {
            return response()->noContent();
        }

        $path = '/'.ltrim(mb_substr((string) ($data['p'] ?? '/'), 0, 190), '/');
        $visitor = (string) ($data['v'] ?? '');
        if ($visitor === '' || str_starts_with($path, '/admin')) {
            return response()->noContent();
        }

        $postId = preg_match('#^/article/([a-z0-9-]+)#', $path, $match)
            ? Post::where('slug', $match[1])->value('id')
            : null;

        // Only the referrer's host, and none for our own pages.
        $referrer = parse_url((string) ($data['r'] ?? ''), PHP_URL_HOST);
        $referrer = $referrer && ! str_ends_with($referrer, 'geovizija.com') ? mb_substr(preg_replace('/^www\./', '', $referrer), 0, 190) : null;

        try {
            DB::table('page_views')->insert([
                'path' => $path,
                'post_id' => $postId,
                'visitor' => substr(hash('sha256', $visitor.'|'.config('app.key')), 0, 16),
                'ip' => $request->ip(),
                'referrer' => $referrer,
                'device' => preg_match('/mobi|android|iphone|ipad/i', $agent) ? 'mobile' : 'desktop',
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never bother the visitor (e.g. before the migration has run).
        }

        return response()->noContent();
    }
}
