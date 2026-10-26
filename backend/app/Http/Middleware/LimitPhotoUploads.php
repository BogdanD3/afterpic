<?php

namespace App\Http\Middleware;

use App\Services\DeviceIdentity;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class LimitPhotoUploads
{
    public const COOKIE = DeviceIdentity::COOKIE;

    public function __construct(private DeviceIdentity $devices) {}

    /**
     * Limit uploads per phone instead of per IP address.
     *
     * Every phone gets a device id (see DeviceIdentity) on its first upload.
     * A phone that uploads in bursts no person can make is blocked for a
     * while; a repeat offence gets a longer block. See photos.upload_limits
     * for the numbers.
     *
     * Every request that passes the checks counts, also ones the validation
     * rejects afterwards. The device id is available to the controller as
     * the `device_id` request attribute.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limits = config('photos.upload_limits');
        $ipKey = 'uploads-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, $limits['per_ip_per_hour'])) {
            return $this->tooManyUploads(RateLimiter::availableIn($ipKey));
        }

        $device = $this->devices->fromRequest($request);
        $isNewDevice = $device === null;

        if ($isNewDevice) {
            $waitSeconds = $this->devices->secondsUntilNewIdAllowed($request);

            if ($waitSeconds > 0) {
                return $this->tooManyUploads($waitSeconds);
            }

            $device = $this->devices->issue($request);
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

        $request->attributes->set('device_id', $device);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limits['per_device_per_hour']);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($hourKey, $limits['per_device_per_hour']));

        if ($isNewDevice) {
            $response->headers->setCookie($this->devices->cookieFor($device, $request));
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

    private function tooManyUploads(int $retryAfterSeconds): JsonResponse
    {
        $retryAfter = max(1, $retryAfterSeconds);

        return response()->json([
            'message' => 'Too many uploads. Try again later.',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
