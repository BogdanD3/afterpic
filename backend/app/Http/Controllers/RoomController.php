<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\AdminAccess;
use App\Services\DeviceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    /**
     * The room behind a link: its name and what its event allows.
     */
    public function show(Room $room): RoomResource
    {
        return new RoomResource($room);
    }

    /**
     * Which admin role the sent key gives in this room: "super", "host",
     * "room" or null. The page uses it to show the admin buttons.
     */
    public function access(Request $request, AdminAccess $access, Room $room): JsonResponse
    {
        $waitSeconds = $access->secondsUntilRetryAllowed($request);

        if ($waitSeconds > 0) {
            return response()->json(['message' => 'Too many attempts.'], 429, ['Retry-After' => (string) $waitSeconds]);
        }

        $role = $access->roleFor($request, $room);

        if ($role === null && $access->presentedAKey($request)) {
            $access->recordFailure($request);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(['role' => $role]);
    }

    /**
     * A guest creates a room for their group inside the event of `$room`.
     *
     * Only when the event allows guest rooms and still accepts uploads. One
     * phone may create photos.events.guest_rooms_per_device_per_day rooms.
     * The answer contains the room's admin key once; the creator keeps it in
     * their admin link.
     */
    public function storeGuestRoom(Request $request, DeviceIdentity $devices, Room $room): JsonResponse
    {
        $event = $room->event;

        if (! $event->guest_rooms_enabled || ! $event->acceptsUploads()) {
            return response()->json(['message' => 'This event does not allow new rooms.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $device = $devices->fromRequest($request);
        $isNewDevice = $device === null;

        if ($isNewDevice) {
            $waitSeconds = $devices->secondsUntilNewIdAllowed($request);

            if ($waitSeconds > 0) {
                return $this->tooManyRooms($waitSeconds);
            }

            $device = $devices->issue($request);
        }

        $limitKey = "guest-rooms-device:{$device}";
        $perDay = (int) config('photos.events.guest_rooms_per_device_per_day');

        if (RateLimiter::tooManyAttempts($limitKey, $perDay)) {
            return $this->tooManyRooms(RateLimiter::availableIn($limitKey));
        }

        RateLimiter::hit($limitKey, 86400);

        $name = trim($validated['name']);

        $guestRoom = $event->rooms()->create([
            'code' => Room::generateCode($name),
            'name' => $name,
            'is_main' => false,
            'admin_key' => Str::random(32),
            'created_by_device' => $device,
        ]);

        $response = (new RoomResource($guestRoom->setRelation('event', $event)))
            ->additional(['admin_key' => $guestRoom->admin_key])
            ->response($request)
            ->setStatusCode(201);

        if ($isNewDevice) {
            $response->headers->setCookie($devices->cookieFor($device, $request));
        }

        return $response;
    }

    private function tooManyRooms(int $retryAfterSeconds): JsonResponse
    {
        $retryAfter = max(1, $retryAfterSeconds);

        return response()->json([
            'message' => 'Too many new rooms from this phone. Try again later.',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
