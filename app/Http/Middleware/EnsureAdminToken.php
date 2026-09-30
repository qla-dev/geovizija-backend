<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards write endpoints with the static ADMIN_API_TOKEN bearer token. */
class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.admin.token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
