<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RequirePhotoAdmin
{
    /**
     * Let the request through only when it carries the configured admin token.
     *
     * Without a configured token the admin routes answer 404, so they are not
     * visible at all. A missing or wrong token answers 401.
     *
     * Only wrong tokens count against photos.admin_rate_limit (per IP per
     * minute): the limit is there to slow down guessing, and the admin can
     * delete many photos in a row. Once an IP is over the limit, every admin
     * request from it is refused until the minute is over, the right token too.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminToken = (string) config('photos.admin_token');

        if ($adminToken === '') {
            abort(404);
        }

        $failuresKey = 'photo-admin-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failuresKey, config('photos.admin_rate_limit'))) {
            $retryAfter = RateLimiter::availableIn($failuresKey);

            return response()->json(['message' => 'Too many attempts.'], 429, ['Retry-After' => (string) $retryAfter]);
        }

        if (! hash_equals($adminToken, (string) $request->header('X-Admin-Token'))) {
            RateLimiter::hit($failuresKey, 60);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
