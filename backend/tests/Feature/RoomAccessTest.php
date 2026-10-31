<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitPhotoUploads;
use App\Models\Event;
use App\Models\Photo;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RoomAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_room_link_shows_the_room_and_what_its_event_allows(): void
    {
        $room = Room::factory()->for(Event::factory()->withGuestRooms()->state(['name' => 'Žurka']))->create(['name' => 'Žurka']);

        $this->getJson("/api/rooms/{$room->code}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Žurka')
            ->assertJsonPath('data.event.main_room_code', $room->code)
            ->assertJsonPath('data.event.guest_rooms_enabled', true)
            ->assertJsonPath('data.event.uploads_open', true)
            ->assertJsonMissingPath('data.event.host_key');
    }

    public function test_unknown_room_code_answers_404(): void
    {
        $this->getJson('/api/rooms/nema-takve-sobe')->assertNotFound();
        $this->getJson('/api/rooms/nema-takve-sobe/photos')->assertNotFound();
    }

    public function test_a_room_never_shows_photos_of_another_room(): void
    {
        $party = Room::factory()->create();
        $travel = Room::factory()->create();
        $partyPhoto = Photo::factory()->for($party)->create();
        Photo::factory()->for($travel)->create();

        $this->getJson($this->photosUrl($party))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $partyPhoto->uuid);

        $this->getJson($this->updatesUrl(0, 0, $party))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $partyPhoto->uuid);
    }

    public function test_deletions_are_only_reported_in_their_own_room(): void
    {
        $party = Room::factory()->create();
        $travel = Room::factory()->create();

        Photo::factory()->for($travel)->create()->deleteWithFiles();

        $this->getJson($this->updatesUrl(0, 0, $party))->assertOk()->assertJsonPath('deleted', []);
    }

    public function test_uploads_land_in_the_room_of_the_link(): void
    {
        $room = Room::factory()->create();

        $uuid = $this->upload($room)->assertAccepted()->json('uuid');

        $this->assertSame($room->id, Photo::where('uuid', $uuid)->value('room_id'));
    }

    public function test_uploads_are_refused_when_the_admin_closed_them(): void
    {
        $room = Room::factory()->for(Event::factory()->state(['uploads_open' => false]))->create();

        $this->upload($room)->assertForbidden();
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_after_expiry_uploads_close_but_the_gallery_stays_open(): void
    {
        $room = Room::factory()->for(Event::factory()->expired())->create();
        Photo::factory()->for($room)->create();

        $this->upload($room)->assertForbidden();

        $this->getJson("/api/rooms/{$room->code}")->assertOk()->assertJsonPath('data.event.uploads_open', false);
        $this->getJson($this->photosUrl($room))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guests_cannot_create_rooms_when_the_event_does_not_allow_it(): void
    {
        $room = Room::factory()->create();

        $this->postJson("/api/rooms/{$room->code}/rooms", ['name' => 'Ekipa'])->assertForbidden();
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_a_guest_creates_a_room_and_gets_its_admin_key(): void
    {
        $main = Room::factory()->for(Event::factory()->withGuestRooms())->create();

        $response = $this->postJson("/api/rooms/{$main->code}/rooms", ['name' => 'Ekipa sa posla'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ekipa sa posla')
            ->assertJsonPath('data.is_main', false)
            ->assertJsonPath('data.event.main_room_code', $main->code);

        $guestRoom = Room::where('code', $response->json('data.code'))->firstOrFail();

        $this->assertSame($main->event_id, $guestRoom->event_id);
        $this->assertSame($guestRoom->admin_key, $response->json('admin_key'));
        $this->assertStringStartsWith('ekipa-sa-posla-', $guestRoom->code);
        $this->assertNotNull($response->getCookie(LimitPhotoUploads::COOKIE, decrypt: false));
    }

    public function test_one_phone_can_create_only_a_few_rooms_per_day(): void
    {
        config(['photos.events.guest_rooms_per_device_per_day' => 2]);
        $main = Room::factory()->for(Event::factory()->withGuestRooms())->create();

        $cookie = $this->postJson("/api/rooms/{$main->code}/rooms", ['name' => 'Prva'])
            ->assertCreated()
            ->getCookie(LimitPhotoUploads::COOKIE, decrypt: false)
            ->getValue();

        $this->withCredentials()->withUnencryptedCookie(LimitPhotoUploads::COOKIE, $cookie);

        $this->postJson("/api/rooms/{$main->code}/rooms", ['name' => 'Druga'])->assertCreated();
        $this->postJson("/api/rooms/{$main->code}/rooms", ['name' => 'Treća'])->assertTooManyRequests();
    }

    public function test_keys_give_the_right_role(): void
    {
        config(['photos.admin_token' => 'super-token']);
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();

        $this->getJson("/api/rooms/{$main->code}/access")->assertOk()->assertJsonPath('role', null);
        $this->getJson("/api/rooms/{$main->code}/access", ['X-Admin-Token' => 'super-token'])->assertJsonPath('role', 'super');
        $this->getJson("/api/rooms/{$guestRoom->code}/access", ['X-Room-Key' => $main->event->host_key])->assertJsonPath('role', 'host');
        $this->getJson("/api/rooms/{$guestRoom->code}/access", ['X-Room-Key' => $guestRoom->admin_key])->assertJsonPath('role', 'room');
        $this->getJson("/api/rooms/{$main->code}/access", ['X-Room-Key' => $guestRoom->admin_key])->assertUnauthorized();
    }

    public function test_a_room_admin_deletes_photos_only_in_their_own_room(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        $own = Photo::factory()->for($guestRoom)->create();
        $notOwn = Photo::factory()->for($main)->create();
        $key = ['X-Room-Key' => $guestRoom->admin_key];

        $this->deleteJson("/api/photos/{$notOwn->uuid}", [], $key)->assertUnauthorized();
        $this->deleteJson("/api/photos/{$own->uuid}", [], $key)->assertNoContent();

        $this->assertModelMissing($own);
        $this->assertModelExists($notOwn);
    }

    public function test_the_host_deletes_photos_in_every_room_of_their_event_only(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        $otherEvent = Room::factory()->create();
        $inGuestRoom = Photo::factory()->for($guestRoom)->create();
        $elsewhere = Photo::factory()->for($otherEvent)->create();
        $key = ['X-Room-Key' => $main->event->host_key];

        $this->deleteJson("/api/photos/{$inGuestRoom->uuid}", [], $key)->assertNoContent();
        $this->deleteJson("/api/photos/{$elsewhere->uuid}", [], $key)->assertUnauthorized();

        $this->assertModelExists($elsewhere);
    }

    public function test_wrong_room_keys_are_rate_limited(): void
    {
        config(['photos.admin_rate_limit' => 2]);
        $room = Room::factory()->guestRoom()->create();
        $photo = Photo::factory()->for($room)->create();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Room-Key' => 'wrong-1'])->assertUnauthorized();
        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Room-Key' => 'wrong-2'])->assertUnauthorized();
        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Room-Key' => $room->admin_key])->assertTooManyRequests();

        $this->assertModelExists($photo);
    }

    public function test_photos_of_a_room_being_deleted_cannot_be_downloaded(): void
    {
        $room = Room::factory()->create();
        $photo = Photo::factory()->for($room)->create();
        Storage::disk('local')->put(Photo::downloadPathFor($photo->original_path), 'download');
        $room->forceFill(['deleting_at' => now()])->save();

        $this->get("/api/photos/{$photo->uuid}/download")->assertNotFound();
        $this->getJson("/api/photos/{$photo->uuid}")->assertNotFound();
    }

    private function upload(Room $room): TestResponse
    {
        return $this->postJson($this->photosUrl($room), [
            'photo' => UploadedFile::fake()->image('photo.jpg', 50, 50),
        ]);
    }
}
