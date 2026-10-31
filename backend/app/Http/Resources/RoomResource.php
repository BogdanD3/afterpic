<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a guest who has the room link may know about the room and its event.
 *
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->event;

        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_main' => $this->is_main,
            'event' => [
                'name' => $event->name,
                'main_room_code' => $event->mainRoom?->code,
                'guest_rooms_enabled' => $event->guest_rooms_enabled,
                'uploads_open' => $event->acceptsUploads(),
                'expires_at' => $event->expires_at?->toIso8601String(),
                'deletes_at' => $event->deletesAt()?->toIso8601String(),
            ],
        ];
    }
}
