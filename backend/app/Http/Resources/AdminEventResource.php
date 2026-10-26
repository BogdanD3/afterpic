<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event as the super admin sees it, with the secret keys, so the host's
 * link and a guest room's admin link can be sent again.
 *
 * @mixin Event
 */
class AdminEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rooms = $this->rooms->whereNull('deleting_at')->sortByDesc('is_main')->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'guest_rooms_enabled' => $this->guest_rooms_enabled,
            'uploads_open' => $this->uploads_open,
            'accepts_uploads' => $this->acceptsUploads(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'deletes_at' => $this->deletesAt()?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'host_key' => $this->host_key,
            'photos_count' => (int) $rooms->sum('photos_count'),
            'rooms' => $rooms->map(fn (Room $room): array => [
                'code' => $room->code,
                'name' => $room->name,
                'is_main' => $room->is_main,
                'admin_key' => $room->admin_key,
                'photos_count' => (int) $room->photos_count,
                'created_at' => $room->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
