<?php

namespace App\Models;

use Database\Factories\ModerationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request in the super admin's inbox (see the migration).
 *
 * room_name and event_name are copied in, so the inbox still says what the
 * request was about after the room is gone.
 */
class ModerationRequest extends Model
{
    /** @use HasFactory<ModerationRequestFactory> */
    use HasFactory;

    public const TYPE_REMOVE_PHOTO = 'remove_photo';

    public const TYPE_DELETE_ROOM = 'delete_room';

    public const STATUS_OPEN = 'open';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'room_id', 'photo_id', 'room_name', 'event_name', 'reason', 'device_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public static function forRoom(Room $room, string $type, string $reason, ?Photo $photo, ?string $deviceId): self
    {
        return self::create([
            'type' => $type,
            'room_id' => $room->id,
            'photo_id' => $photo?->id,
            'room_name' => $room->name,
            'event_name' => $room->event->name,
            'reason' => $reason,
            'device_id' => $deviceId,
        ]);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Photo, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function resolve(string $status): void
    {
        $this->forceFill(['status' => $status, 'resolved_at' => now()])->save();
    }
}
