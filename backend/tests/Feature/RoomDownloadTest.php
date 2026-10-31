<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class RoomDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_the_host_gets_a_zip_with_every_photo_of_the_room(): void
    {
        $room = Room::factory()->create(['name' => 'Rođendan Ana']);
        $photos = Photo::factory()->count(3)->for($room)->create();
        Photo::factory()->pending()->for($room)->create();

        foreach ($photos as $photo) {
            Storage::disk('local')->put(Photo::downloadPathFor($photo->original_path), 'jpeg bytes');
        }

        $url = $this->postJson("/api/rooms/{$room->code}/download-link", [], ['X-Room-Key' => $room->event->host_key])
            ->assertOk()
            ->json('url');

        $this->assertStringStartsWith("/api/rooms/{$room->code}/download-all?", $url);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('rodendan-ana-fotografije.zip', $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());

        $this->assertSame(3, $zip->numFiles);
    }

    public function test_a_guest_cannot_get_a_download_link(): void
    {
        $room = Room::factory()->create();

        $this->postJson("/api/rooms/{$room->code}/download-link")->assertUnauthorized();
    }

    public function test_the_zip_needs_a_valid_signature(): void
    {
        $room = Room::factory()->create();

        $this->get("/api/rooms/{$room->code}/download-all")->assertForbidden();
    }

    public function test_a_download_link_expires(): void
    {
        $room = Room::factory()->create();

        $url = $this->postJson("/api/rooms/{$room->code}/download-link", [], ['X-Room-Key' => $room->event->host_key])->json('url');

        $this->travel(11)->minutes();

        $this->get($url)->assertForbidden();
    }
}
