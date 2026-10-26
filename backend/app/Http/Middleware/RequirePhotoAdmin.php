<?php

namespace App\Http\Middleware;

use App\Services\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePhotoAdmin
{
    public function __construct(private AdminAccess $access) {}

    /**
     * Let the request through only with the super admin token (X-Admin-Token).
     *
     * Without a configured token the admin routes answer 404, so they are not
     * visible at all. A missing or wrong token answers 401 and counts against
     * the per-IP limit for wrong keys (see AdminAccess).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->isSuperAdminConfigured()) {
            abort(404);
        }

        $waitSeconds = $this->access->secondsUntilRetryAllowed($request);

        if ($waitSeconds > 0) {
            return response()->json(['message' => 'Too many attempts.'], 429, ['Retry-After' => (string) $waitSeconds]);
        }

        if (! $this->access->isSuperAdmin($request)) {
            $this->access->recordFailure($request);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
