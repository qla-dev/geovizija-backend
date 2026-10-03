<?php

namespace App\Http\Middleware;

use App\Services\AdminPanel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards admin endpoints with the static ADMIN_API_TOKEN bearer token or an admin panel login token. */
class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.admin.token');
        $given = (string) $request->bearerToken();

        $static = $expected !== '' && $given !== '' && hash_equals($expected, $given);
        if (! $static && ! AdminPanel::valid($given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
