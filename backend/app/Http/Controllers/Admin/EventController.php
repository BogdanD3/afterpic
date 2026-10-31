<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use App\Services\RoomDeleter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Super admin only (RequirePhotoAdmin): the events you sell.
 */
class EventController extends Controller
{
    /**
     * Every event, newest first, with its rooms and photo counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $events = Event::active()
            ->with(['rooms' => fn (HasMany $rooms) => $rooms->withCount('photos')])
            ->latest()
            ->get();

        return AdminEventResource::collection($events);
    }

    /**
     * Create an event with its main room.
     *
     * `days`: until expiry, 0 for never; left out, PHOTOS_EVENT_DAYS applies.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'guest_rooms_enabled' => ['boolean'],
        ]);

        $event = Event::createWithMainRoom(
            trim($validated['name']),
            $validated['days'] ?? null,
            (bool) ($validated['guest_rooms_enabled'] ?? false),
        );

        return $this->eventResponse($event)->setStatusCode(201);
    }

    /**
     * Change an event: name, guest rooms, uploads open, or a new expiry
     * (`days` from now, 0 for never). The main room keeps its name in step.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        abort_if($event->deleting_at !== null, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'guest_rooms_enabled' => ['sometimes', 'boolean'],
            'uploads_open' => ['sometimes', 'boolean'],
            'days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        if (array_key_exists('days', $validated)) {
            $event->expires_at = $validated['days'] > 0 ? now()->addDays($validated['days']) : null;
            unset($validated['days']);
        }

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
            $event->mainRoom?->update(['name' => $validated['name']]);
        }

        $event->fill($validated)->save();

        return $this->eventResponse($event);
    }

    /**
     * Delete the event with all rooms and photos (in the background).
     */
    public function destroy(Event $event, RoomDeleter $deleter): JsonResponse
    {
        abort_if($event->deleting_at !== null, 404);

        $deleter->deleteEvent($event);

        return response()->json(['message' => 'The event is being deleted.'], 202);
    }

    private function eventResponse(Event $event): JsonResponse
    {
        $event->load(['rooms' => fn (HasMany $rooms) => $rooms->withCount('photos')]);

        return (new AdminEventResource($event))->response();
    }
}
