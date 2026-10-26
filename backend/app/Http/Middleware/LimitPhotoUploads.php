<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class LimitPhotoUploads
{
    public const COOKIE = 'photo_device';

    private const DEVICE_ID_LENGTH = 40;

    /**
     * Limit uploads per phone instead of per IP address.
     *
     * Every phone gets a random device id in a signed cookie on its first
     * upload. The signature (HMAC with APP_KEY) means a made-up or edited
     * cookie is ignored, so the only way to get a fresh allowance is to drop
     * the cookie, and new ids are limited per IP. A phone that uploads in
     * bursts no person can make is blocked for a while; a repeat offence
     * gets a longer block. See photos.upload_limits for the numbers.
     *
     * Every request that passes the checks counts, also ones the validation
     * rejects afterwards.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limits = config('photos.upload_limits');
        $ipKey = 'uploads-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, $limits['per_ip_per_hour'])) {
            return $this->tooManyUploads(RateLimiter::availableIn($ipKey));
        }

        $device = $this->deviceFromCookie($request);
        $isNewDevice = $device === null;

        if ($isNewDevice) {
            $newDevicesKey = 'upload-devices-ip:'.$request->ip();

            if (RateLimiter::tooManyAttempts($newDevicesKey, $limits['new_devices_per_ip_per_hour'])) {
                return $this->tooManyUploads(RateLimiter::availableIn($newDevicesKey));
            }

            RateLimiter::hit($newDevicesKey, 3600);
            $device = Str::random(self::DEVICE_ID_LENGTH);
        }

        $blockedUntil = Cache::get("uploads-blocked:{$device}");

        if ($blockedUntil !== null) {
            return $this->tooManyUploads($blockedUntil - now()->timestamp);
        }

        $hourKey = "uploads-device-hour:{$device}";
        $burstKey = "uploads-device-burst:{$device}";

        if (RateLimiter::tooManyAttempts($hourKey, $limits['per_device_per_hour'])) {
            return $this->tooManyUploads(RateLimiter::availableIn($hourKey));
        }

        if (RateLimiter::tooManyAttempts($burstKey, $limits['burst'])) {
            RateLimiter::clear($burstKey);

            return $this->tooManyUploads($this->block($device, $limits));
        }

        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($hourKey, 3600);
        RateLimiter::hit($burstKey, $limits['burst_minutes'] * 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limits['per_device_per_hour']);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($hourKey, $limits['per_device_per_hour']));

        if ($isNewDevice) {
            $response->headers->setCookie($this->deviceCookie($device, $request, $limits['device_cookie_days']));
        }

        return $response;
    }

    /**
     * Block the device and return the block length in seconds. The first
     * block is short; another one before the strikes expire is longer.
     *
     * @param  array<string, int>  $limits
     */
    private function block(string $device, array $limits): int
    {
        $strikes = RateLimiter::hit("uploads-strikes:{$device}", $limits['strikes_reset_hours'] * 3600);
        $minutes = $strikes > 1 ? $limits['repeat_block_minutes'] : $limits['block_minutes'];
        $until = now()->addMinutes($minutes);

        Cache::put("uploads-blocked:{$device}", $until->timestamp, $until);

        return $minutes * 60;
    }

    private function deviceFromCookie(Request $request): ?string
    {
        $parts = explode('.', (string) $request->cookies->get(self::COOKIE), 2);

        if (count($parts) !== 2 || strlen($parts[0]) !== self::DEVICE_ID_LENGTH) {
            return null;
        }

        [$device, $signature] = $parts;

        return hash_equals($this->sign($device), $signature) ? $device : null;
    }

    private function sign(string $device): string
    {
        return hash_hmac('sha256', $device, (string) config('app.key'));
    }

    private function deviceCookie(string $device, Request $request, int $days): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue("{$device}.{$this->sign($device)}")
            ->withExpires(now()->addDays($days))
            ->withPath('/api')
            // Behind the proxies PHP sees plain HTTP, so APP_URL decides.
            ->withSecure($request->isSecure() || str_starts_with((string) config('app.url'), 'https://'))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private function tooManyUploads(int $retryAfterSeconds): JsonResponse
    {
        $retryAfter = max(1, $retryAfterSeconds);

        return response()->json([
            'message' => 'Too many uploads. Try again later.',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
