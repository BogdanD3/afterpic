<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Photo;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super admin only (RequirePhotoAdmin): the phones that uploaded, to find
 * who uploaded what, remove everything from one phone and block it.
 */
class DeviceController extends Controller
{
    /**
     * Phones that uploaded to an event, most photos first.
     */
    public function index(Request $request): JsonResponse
    {
        $eventId = (int) $request->validate(['event_id' => ['required', 'integer']])['event_id'];
        $inEvent = fn (Builder $photos) => $photos->whereHas('room', fn (Builder $room) => $room->where('event_id', $eventId));

        $devices = Device::query()
            ->whereHas('photos', $inEvent)
            ->withCount(['photos as photos_count' => $inEvent])
            ->orderByDesc('photos_count')
            ->get();

        return response()->json(['data' => $devices->map(fn (Device $device): array => $this->describe($device))]);
    }

    /**
     * The phone that uploaded a photo, or null for photos from before
     * devices were kept.
     */
    public function forPhoto(string $uuid): JsonResponse
    {
        $photo = Photo::with('device')->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $photo->device ? $this->describe($photo->device) : null]);
    }

    /**
     * Delete every photo of the phone in one event, or everywhere when
     * neither is given. The event is named by `event_id` (admin page) or by
     * the code of one of its rooms, `room` (from a photo in a room).
     */
    public function destroyPhotos(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['nullable', 'integer'],
            'room' => ['nullable', 'string'],
        ]);

        $eventId = isset($validated['room'])
            ? Room::where('code', $validated['room'])->firstOrFail()->event_id
            : ($validated['event_id'] ?? null);
        $deleted = 0;
        $failed = 0;

        $device->photos()
            ->when($eventId !== null, fn (Builder $photos) => $photos->whereHas('room', fn (Builder $room) => $room->where('event_id', $eventId)))
            ->chunkById(100, function ($photos) use (&$deleted, &$failed): void {
                foreach ($photos as $photo) {
                    $photo->deleteWithFiles() ? $deleted++ : $failed++;
                }
            });

        return response()->json(['deleted' => $deleted, 'failed' => $failed], $failed > 0 ? 500 : 200);
    }

    /**
     * A blocked phone cannot upload or create rooms anymore. Its photos stay
     * until they are deleted.
     */
    public function block(Device $device): JsonResponse
    {
        $device->forceFill(['blocked_at' => now()])->save();

        return response()->json(['data' => $this->describe($device)]);
    }

    public function unblock(Device $device): JsonResponse
    {
        $device->forceFill(['blocked_at' => null])->save();

        return response()->json(['data' => $this->describe($device)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'client' => $device->client,
            'blocked' => $device->blocked_at !== null,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'photos_count' => $device->photos_count ?? null,
        ];
    }
}
