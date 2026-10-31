<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who may manage what. There are no accounts, only secret keys:
 *
 * - super: PHOTOS_ADMIN_TOKEN in the X-Admin-Token header. Everything.
 * - host: the event's host_key in the X-Room-Key header. Every room of that event.
 * - room: a guest room's admin_key in the X-Room-Key header. That room only.
 *
 * Wrong keys count against photos.admin_rate_limit per IP per minute, which
 * slows down guessing. Right keys never count, so an admin can delete many
 * photos in a row.
 */
class AdminAccess
{
    public const ROLE_SUPER = 'super';

    public const ROLE_HOST = 'host';

    public const ROLE_ROOM = 'room';

    public function isSuperAdminConfigured(): bool
    {
        return (string) config('photos.admin_token') !== '';
    }

    public function isSuperAdmin(Request $request): bool
    {
        return $this->isSuperAdminConfigured()
            && hash_equals((string) config('photos.admin_token'), (string) $request->header('X-Admin-Token'));
    }

    /**
     * The strongest role the request has for this room, or null.
     */
    public function roleFor(Request $request, Room $room): ?string
    {
        if ($this->isSuperAdmin($request)) {
            return self::ROLE_SUPER;
        }

        $key = (string) $request->header('X-Room-Key');

        if ($key === '') {
            return null;
        }

        if (hash_equals((string) $room->event->host_key, $key)) {
            return self::ROLE_HOST;
        }

        if ($room->admin_key !== null && hash_equals((string) $room->admin_key, $key)) {
            return self::ROLE_ROOM;
        }

        return null;
    }

    public function presentedAKey(Request $request): bool
    {
        return (string) $request->header('X-Admin-Token') !== '' || (string) $request->header('X-Room-Key') !== '';
    }

    /**
     * Seconds until this IP may try a key again, or 0 when it may now.
     */
    public function secondsUntilRetryAllowed(Request $request): int
    {
        $key = $this->failuresKey($request);

        return RateLimiter::tooManyAttempts($key, config('photos.admin_rate_limit'))
            ? max(1, RateLimiter::availableIn($key))
            : 0;
    }

    public function recordFailure(Request $request): void
    {
        RateLimiter::hit($this->failuresKey($request), 60);
    }

    private function failuresKey(Request $request): string
    {
        return 'photo-admin-failures:'.$request->ip();
    }
}
