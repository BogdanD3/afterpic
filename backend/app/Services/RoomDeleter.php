<?php

namespace App\Services;

use App\Jobs\DeleteEventJob;
use App\Jobs\DeleteRoomJob;
use App\Models\Event;
use App\Models\Room;

/**
 * Deleting a room or a whole event: hidden right away, photos removed in the
 * background, because a room can hold hundreds of photos.
 */
class RoomDeleter
{
    public function deleteRoom(Room $room): void
    {
        $room->forceFill(['deleting_at' => now()])->save();

        DeleteRoomJob::dispatch($room->id);
    }

    public function deleteEvent(Event $event): void
    {
        $event->forceFill(['deleting_at' => now()])->save();

        DeleteEventJob::dispatch($event->id);
    }

    /**
     * Delete every photo of the room with its files.
     *
     * @return bool false when some photo could not be deleted (it is kept and retried)
     */
    public function deletePhotosOf(Room $room): bool
    {
        $allDeleted = true;

        $room->photos()->chunkById(100, function ($photos) use (&$allDeleted): void {
            foreach ($photos as $photo) {
                $allDeleted = $photo->deleteWithFiles() && $allDeleted;
            }
        });

        return $allDeleted;
    }
}
