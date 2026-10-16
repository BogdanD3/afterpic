<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePhotoAdmin
{
    /**
     * Let the request through only when it carries the configured admin token.
     *
     * Without a configured token the admin routes answer 404, so they are not
     * visible at all. A missing or wrong token answers 401.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminToken = (string) config('photos.admin_token');

        if ($adminToken === '') {
            abort(404);
        }

        if (! hash_equals($adminToken, (string) $request->header('X-Admin-Token'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
