<?php

namespace App\Http\Controllers;

use App\Models\ModerationRequest;
use App\Models\Photo;
use App\Models\Room;
use App\Services\AdminAccess;
use App\Services\DeviceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Requests that go to the super admin's inbox. Nothing is deleted here; the
 * super admin approves or rejects on /admin.
 */
class ModerationRequestController extends Controller
{
    private const PER_HOUR = 10;

    /**
     * Any guest asks for a photo to be removed ("this is me"), with a reason.
     */
    public function photoRemoval(Request $request, DeviceIdentity $devices, string $uuid): JsonResponse
    {
        $photo = Photo::visible()->with('room.event')->where('uuid', $uuid)->firstOrFail();
        $device = $devices->fromRequest($request);

        if ($limited = $this->limit($request, $device)) {
            return $limited;
        }

        ModerationRequest::forRoom($photo->room, ModerationRequest::TYPE_REMOVE_PHOTO, $this->reason($request), $photo, $device);

        return response()->json(['message' => 'The request was sent.'], 201);
    }

    /**
     * The host or a guest room's admin asks for the room to be deleted. From
     * the main room (host) this asks for the whole event.
     */
    public function roomDeletion(Request $request, AdminAccess $access, DeviceIdentity $devices, Room $room): JsonResponse
    {
        $waitSeconds = $access->secondsUntilRetryAllowed($request);

        if ($waitSeconds > 0) {
            return response()->json(['message' => 'Too many attempts.'], 429, ['Retry-After' => (string) $waitSeconds]);
        }

        $role = $access->roleFor($request, $room);

        if (! in_array($role, [AdminAccess::ROLE_HOST, AdminAccess::ROLE_ROOM], true)) {
            if ($access->presentedAKey($request)) {
                $access->recordFailure($request);
            }

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $device = $devices->fromRequest($request);

        if ($limited = $this->limit($request, $device)) {
            return $limited;
        }

        ModerationRequest::forRoom($room, ModerationRequest::TYPE_DELETE_ROOM, $this->reason($request), null, $device);

        return response()->json(['message' => 'The request was sent.'], 201);
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);
    }

    private function limit(Request $request, ?string $device): ?JsonResponse
    {
        $key = 'moderation-requests:'.($device ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 3600);

        return null;
    }
}
