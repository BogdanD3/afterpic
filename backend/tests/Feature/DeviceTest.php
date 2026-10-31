<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitPhotoUploads;
use App\Models\Device;
use App\Models\Event;
use App\Models\Photo;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'test-admin-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        config(['photos.admin_token' => self::ADMIN_TOKEN]);
    }

    public function test_an_upload_remembers_the_phone_and_what_it_is(): void
    {
        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1')
            ->upload()
            ->assertAccepted();

        $photo = Photo::where('uuid', $response->json('uuid'))->firstOrFail();

        $this->assertNotNull($photo->device_id);
        $this->assertSame('iPhone · Safari', Device::findOrFail($photo->device_id)->client);
    }

    public function test_a_guest_sets_a_name_that_appears_under_all_their_photos(): void
    {
        $cookie = $this->upload()->getCookie(LimitPhotoUploads::COOKIE, decrypt: false)->getValue();
        $this->withCredentials()->withUnencryptedCookie(LimitPhotoUploads::COOKIE, $cookie);

        $this->putJson('/api/me/name', ['name' => '  Ana  '])->assertOk()->assertJsonPath('name', 'Ana');
        $this->getJson('/api/me')->assertJsonPath('name', 'Ana');

        Photo::query()->update(['status' => Photo::STATUS_PROCESSED, 'web_path' => 'w', 'thumb_path' => 't']);

        $this->getJson($this->photosUrl())->assertOk()->assertJsonPath('data.0.uploader_name', 'Ana');
    }

    public function test_an_empty_name_clears_it(): void
    {
        $device = Device::factory()->named('Ana')->create();
        $this->withCredentials()->withUnencryptedCookie(LimitPhotoUploads::COOKIE, $this->cookieFor($device));

        $this->putJson('/api/me/name', ['name' => ''])->assertOk()->assertJsonPath('name', null);
    }

    public function test_photos_never_show_the_device_id(): void
    {
        Photo::factory()->for(Device::factory()->named('Ana'))->create();

        $photo = $this->getJson($this->photosUrl())->assertOk()->json('data.0');

        $this->assertSame('Ana', $photo['uploader_name']);
        $this->assertArrayNotHasKey('device_id', $photo);
    }

    public function test_a_blocked_phone_cannot_upload_or_create_rooms(): void
    {
        $device = Device::factory()->create(['blocked_at' => now()]);
        $main = Room::factory()->for(Event::factory()->withGuestRooms())->create();
        $this->withCredentials()->withUnencryptedCookie(LimitPhotoUploads::COOKIE, $this->cookieFor($device));

        $this->upload($main)->assertForbidden();
        $this->postJson("/api/rooms/{$main->code}/rooms", ['name' => 'Ekipa'])->assertForbidden();
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_admin_lists_the_phones_that_uploaded_to_an_event(): void
    {
        $room = Room::factory()->create();
        $ana = Device::factory()->named('Ana')->create();
        $other = Device::factory()->create();
        Photo::factory()->count(2)->for($room)->for($ana)->create();
        Photo::factory()->for(Room::factory())->for($other)->create();

        $this->getJson("/api/admin/devices?event_id={$room->event_id}", $this->admin())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ana->id)
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.photos_count', 2);
    }

    public function test_admin_sees_which_phone_uploaded_a_photo(): void
    {
        $photo = Photo::factory()->for(Device::factory()->named('Ana'))->create();

        $this->getJson("/api/admin/photos/{$photo->uuid}/device", $this->admin())
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana');
    }

    public function test_admin_deletes_every_photo_of_a_phone_in_one_event_only(): void
    {
        $device = Device::factory()->create();
        $room = Room::factory()->create();
        $elsewhere = Room::factory()->create();
        Photo::factory()->count(2)->for($room)->for($device)->create();
        $kept = Photo::factory()->for($elsewhere)->for($device)->create();

        $this->deleteJson("/api/admin/devices/{$device->id}/photos?event_id={$room->event_id}", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseCount('photos', 1);
        $this->assertModelExists($kept);
    }

    public function test_the_event_can_be_named_by_one_of_its_room_codes(): void
    {
        $device = Device::factory()->create();
        $room = Room::factory()->create();
        Photo::factory()->for($room)->for($device)->create();
        Photo::factory()->for(Room::factory())->for($device)->create();

        $this->deleteJson("/api/admin/devices/{$device->id}/photos?room={$room->code}", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('deleted', 1);
    }

    public function test_admin_blocks_and_unblocks_a_phone(): void
    {
        $device = Device::factory()->create();

        $this->postJson("/api/admin/devices/{$device->id}/block", [], $this->admin())->assertOk()->assertJsonPath('data.blocked', true);
        $this->assertTrue(Device::isBlocked($device->id));

        $this->deleteJson("/api/admin/devices/{$device->id}/block", [], $this->admin())->assertOk()->assertJsonPath('data.blocked', false);
        $this->assertFalse(Device::isBlocked($device->id));
    }

    public function test_phone_endpoints_need_the_admin_token(): void
    {
        $device = Device::factory()->create();

        $this->postJson("/api/admin/devices/{$device->id}/block")->assertUnauthorized();
        $this->assertFalse(Device::isBlocked($device->id));
    }

    public function test_client_description_keeps_only_platform_and_browser(): void
    {
        $this->assertSame('Android · Chrome', Device::describeClient('Mozilla/5.0 (Linux; Android 15; SM-S928B) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'));
        $this->assertSame('Android · Samsung Internet', Device::describeClient('Mozilla/5.0 (Linux; Android 15) SamsungBrowser/27.0 Chrome/125.0 Mobile Safari/537.36'));
        $this->assertNull(Device::describeClient('curl/8.0'));
    }

    private function upload(?Room $room = null): TestResponse
    {
        return $this->postJson($this->photosUrl($room), [
            'photo' => UploadedFile::fake()->image('photo.jpg', 50, 50),
        ]);
    }

    /**
     * The cookie value the server would have given this device.
     */
    private function cookieFor(Device $device): string
    {
        return $device->id.'.'.hash_hmac('sha256', $device->id, (string) config('app.key'));
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['X-Admin-Token' => self::ADMIN_TOKEN];
    }
}
