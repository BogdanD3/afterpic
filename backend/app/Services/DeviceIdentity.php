<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The phone's id: a random value in a signed cookie, because guests have no
 * accounts.
 *
 * The signature (HMAC with APP_KEY) means a made-up or edited cookie is
 * ignored, so the only way to get a fresh id is to drop the cookie, and new
 * ids are limited per IP (photos.upload_limits.new_devices_per_ip_per_hour).
 */
class DeviceIdentity
{
    public const COOKIE = 'photo_device';

    private const ID_LENGTH = 40;

    /**
     * The device id from a valid cookie, or null.
     */
    public function fromRequest(Request $request): ?string
    {
        $parts = explode('.', (string) $request->cookies->get(self::COOKIE), 2);

        if (count($parts) !== 2 || strlen($parts[0]) !== self::ID_LENGTH) {
            return null;
        }

        [$device, $signature] = $parts;

        return hash_equals($this->sign($device), $signature) ? $device : null;
    }

    /**
     * Seconds until this IP may get a new device id, or 0 when it may now.
     */
    public function secondsUntilNewIdAllowed(Request $request): int
    {
        $key = $this->newIdsKey($request);

        return RateLimiter::tooManyAttempts($key, config('photos.upload_limits.new_devices_per_ip_per_hour'))
            ? max(1, RateLimiter::availableIn($key))
            : 0;
    }

    /**
     * A new device id, counted against the IP's allowance. Check
     * secondsUntilNewIdAllowed() first; send cookieFor() with the response.
     */
    public function issue(Request $request): string
    {
        RateLimiter::hit($this->newIdsKey($request), 3600);

        return Str::random(self::ID_LENGTH);
    }

    public function cookieFor(string $device, Request $request): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue("{$device}.{$this->sign($device)}")
            ->withExpires(now()->addDays((int) config('photos.upload_limits.device_cookie_days')))
            ->withPath('/api')
            // Behind the proxies PHP sees plain HTTP, so APP_URL decides.
            ->withSecure($request->isSecure() || str_starts_with((string) config('app.url'), 'https://'))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private function newIdsKey(Request $request): string
    {
        return 'upload-devices-ip:'.$request->ip();
    }

    private function sign(string $device): string
    {
        return hash_hmac('sha256', $device, (string) config('app.key'));
    }
}
