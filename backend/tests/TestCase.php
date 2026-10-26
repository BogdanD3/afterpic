<?php

namespace Tests;

use App\Models\Room;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;

abstract class TestCase extends BaseTestCase
{
    /**
     * The room most tests work in: the first room in the database, which is
     * also where Photo::factory() puts photos unless told otherwise.
     */
    protected function room(): Room
    {
        return Room::query()->oldest('id')->first() ?? Room::factory()->create();
    }

    protected function photosUrl(?Room $room = null): string
    {
        return '/api/rooms/'.($room ?? $this->room())->code.'/photos';
    }

    /**
     * Polling cursor as the server hands it out (see PhotoController::updates).
     */
    protected function updatesCursor(int $afterId, int $since = 0, ?Room $room = null): string
    {
        return Crypt::encryptString(json_encode([
            'room_id' => ($room ?? $this->room())->id,
            'after_id' => $afterId,
            'since' => $since,
        ]));
    }

    protected function updatesUrl(int $afterId, int $since = 0, ?Room $room = null): string
    {
        return $this->photosUrl($room).'/updates?cursor='.urlencode($this->updatesCursor($afterId, $since, $room));
    }
}
