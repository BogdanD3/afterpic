<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationRequest;
use App\Services\RoomDeleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Super admin only (RequirePhotoAdmin): the inbox of removal and deletion requests.
 */
class ModerationRequestController extends Controller
{
    /**
     * Open requests first, then the most recent resolved ones.
     */
    public function index(Request $request): JsonResponse
    {
        $onlyOpen = $request->query('status', 'open') === 'open';

        $requests = ModerationRequest::query()
            ->with(['room', 'photo', 'device'])
            ->when($onlyOpen, fn ($query) => $query->where('status', ModerationRequest::STATUS_OPEN))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->latest()
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $requests->map(fn (ModerationRequest $item): array => [
                'id' => $item->id,
                'type' => $item->type,
                'status' => $item->status,
                'reason' => $item->reason,
                'room_name' => $item->room_name,
                'event_name' => $item->event_name,
                'room_code' => $item->room?->deleting_at === null ? $item->room?->code : null,
                'is_main_room' => (bool) $item->room?->is_main,
                'photo' => $item->photo?->thumb_path === null ? null : [
                    'uuid' => $item->photo->uuid,
                    'thumb_url' => Storage::disk('public')->url($item->photo->thumb_path),
                ],
                'from' => $item->device?->name,
                'created_at' => $item->created_at->toIso8601String(),
                'resolved_at' => $item->resolved_at?->toIso8601String(),
            ]),
            'open_count' => ModerationRequest::where('status', ModerationRequest::STATUS_OPEN)->count(),
        ]);
    }

    /**
     * Do what was asked: delete the photo, or the room (the whole event when
     * it is the main room). Already gone counts as done.
     */
    public function approve(ModerationRequest $moderationRequest, RoomDeleter $deleter): JsonResponse
    {
        abort_unless($moderationRequest->status === ModerationRequest::STATUS_OPEN, 409, 'This request is already resolved.');

        if ($moderationRequest->type === ModerationRequest::TYPE_REMOVE_PHOTO) {
            if ($moderationRequest->photo && ! $moderationRequest->photo->deleteWithFiles()) {
                return response()->json(['message' => 'The photo could not be deleted. Try again.'], 500);
            }
        } elseif ($room = $moderationRequest->room) {
            $room->is_main ? $deleter->deleteEvent($room->event) : $deleter->deleteRoom($room);
        }

        $moderationRequest->resolve(ModerationRequest::STATUS_APPROVED);

        return response()->json(['status' => $moderationRequest->status]);
    }

    public function reject(ModerationRequest $moderationRequest): JsonResponse
    {
        abort_unless($moderationRequest->status === ModerationRequest::STATUS_OPEN, 409, 'This request is already resolved.');

        $moderationRequest->resolve(ModerationRequest::STATUS_REJECTED);

        return response()->json(['status' => $moderationRequest->status]);
    }
}
