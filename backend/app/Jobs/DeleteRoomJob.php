<?php

namespace App\Jobs;

use App\Models\Room;
use App\Services\RoomDeleter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class DeleteRoomJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Below DB_QUEUE_RETRY_AFTER (180). Deleting about 10,000 photos fits; a
     * bigger job is cut off and simply continues on its next attempt.
     */
    public int $timeout = 110;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 3600];

    public function __construct(public int $roomId) {}

    /**
     * Delete the room's photos, then the room. A photo whose files cannot be
     * deleted keeps the room in place, and the job tries again later.
     */
    public function handle(RoomDeleter $deleter): void
    {
        $room = Room::find($this->roomId);

        if ($room === null) {
            return;
        }

        if (! $deleter->deletePhotosOf($room)) {
            throw new RuntimeException("Some photos of room {$room->id} could not be deleted.");
        }

        $room->delete();
    }
}
