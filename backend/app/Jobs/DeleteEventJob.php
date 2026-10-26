<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\RoomDeleter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class DeleteEventJob implements ShouldQueue
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

    public function __construct(public int $eventId) {}

    /**
     * Delete the photos of every room, then the event (its rooms go with it).
     */
    public function handle(RoomDeleter $deleter): void
    {
        $event = Event::find($this->eventId);

        if ($event === null) {
            return;
        }

        $allDeleted = true;

        foreach ($event->rooms as $room) {
            $allDeleted = $deleter->deletePhotosOf($room) && $allDeleted;
        }

        if (! $allDeleted) {
            throw new RuntimeException("Some photos of event {$event->id} could not be deleted.");
        }

        $event->delete();
    }
}
