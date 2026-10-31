<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventAdminTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'test-admin-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config(['photos.admin_token' => self::ADMIN_TOKEN, 'photos.events.default_days' => 30]);
    }

    public function test_admin_creates_an_event_with_a_main_room_that_expires_after_the_default_days(): void
    {
        $this->freezeSecond();

        $response = $this->postJson('/api/admin/events', ['name' => 'Rođendan Ana'], $this->admin())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rođendan Ana')
            ->assertJsonPath('data.guest_rooms_enabled', false)
            ->assertJsonPath('data.expires_at', now()->addDays(30)->toIso8601String())
            ->assertJsonCount(1, 'data.rooms')
            ->assertJsonPath('data.rooms.0.is_main', true);

        $this->assertMatchesRegularExpression('/^rodendan-ana-[a-z0-9]{10}$/', $response->json('data.rooms.0.code'));
        $this->assertSame(32, strlen($response->json('data.host_key')));
    }

    public function test_expiry_can_be_chosen_per_event_or_turned_off(): void
    {
        $this->freezeSecond();

        $this->postJson('/api/admin/events', ['name' => 'Kratko', 'days' => 15], $this->admin())
            ->assertJsonPath('data.expires_at', now()->addDays(15)->toIso8601String());

        $this->postJson('/api/admin/events', ['name' => 'Demo', 'days' => 0], $this->admin())
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.deletes_at', null);
    }

    public function test_admin_endpoints_need_the_admin_token(): void
    {
        $this->getJson('/api/admin/events')->assertUnauthorized();
        $this->postJson('/api/admin/events', ['name' => 'X'], ['X-Admin-Token' => 'wrong'])->assertUnauthorized();
    }

    public function test_admin_endpoints_do_not_exist_without_a_configured_token(): void
    {
        config(['photos.admin_token' => null]);

        $this->getJson('/api/admin/events', ['X-Admin-Token' => ''])->assertNotFound();
    }

    public function test_events_are_listed_with_their_rooms_and_photo_counts(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        Photo::factory()->count(2)->for($main)->create();
        Photo::factory()->for($guestRoom)->create();

        $this->getJson('/api/admin/events', $this->admin())
            ->assertOk()
            ->assertJsonPath('data.0.photos_count', 3)
            ->assertJsonPath('data.0.rooms.0.code', $main->code)
            ->assertJsonPath('data.0.rooms.0.photos_count', 2)
            ->assertJsonPath('data.0.rooms.1.admin_key', $guestRoom->admin_key);
    }

    public function test_admin_changes_an_event(): void
    {
        $this->freezeSecond();
        $room = Room::factory()->create();

        $this->patchJson("/api/admin/events/{$room->event_id}", [
            'name' => 'Nova žurka',
            'guest_rooms_enabled' => true,
            'uploads_open' => false,
            'days' => 45,
        ], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.name', 'Nova žurka')
            ->assertJsonPath('data.guest_rooms_enabled', true)
            ->assertJsonPath('data.accepts_uploads', false)
            ->assertJsonPath('data.expires_at', now()->addDays(45)->toIso8601String());

        $this->assertSame('Nova žurka', $room->fresh()->name);
    }

    public function test_deleting_an_event_removes_every_room_and_photo_with_their_files(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        $photos = [Photo::factory()->for($main)->create(), Photo::factory()->for($guestRoom)->create()];

        foreach ($photos as $photo) {
            Storage::disk('public')->put("photos/{$photo->uuid}/web.webp", 'web');
        }

        $event = $main->event;

        $this->deleteJson("/api/admin/events/{$event->id}", [], $this->admin())->assertAccepted();

        $this->assertModelMissing($event);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('photos', 0);

        foreach ($photos as $photo) {
            Storage::disk('public')->assertMissing("photos/{$photo->uuid}");
        }
    }

    public function test_an_event_being_deleted_cannot_be_opened_anymore(): void
    {
        $room = Room::factory()->create();
        $room->event->forceFill(['deleting_at' => now()])->save();

        $this->getJson("/api/rooms/{$room->code}")->assertNotFound();
        $this->getJson($this->photosUrl($room))->assertNotFound();
    }

    public function test_admin_deletes_a_guest_room_but_not_the_main_room(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        Photo::factory()->for($guestRoom)->create();

        $this->deleteJson("/api/admin/rooms/{$main->code}", [], $this->admin())->assertUnprocessable();
        $this->deleteJson("/api/admin/rooms/{$guestRoom->code}", [], $this->admin())->assertAccepted();

        $this->assertModelMissing($guestRoom);
        $this->assertModelExists($main);
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_a_new_link_replaces_the_old_one(): void
    {
        $room = Room::factory()->create();
        $oldCode = $room->code;

        $newCode = $this->postJson("/api/admin/rooms/{$oldCode}/rotate-code", [], $this->admin())
            ->assertOk()
            ->json('code');

        $this->assertNotSame($oldCode, $newCode);
        $this->getJson("/api/rooms/{$oldCode}")->assertNotFound();
        $this->getJson("/api/rooms/{$newCode}")->assertOk();
    }

    public function test_expired_events_are_deleted_after_the_grace_days(): void
    {
        config(['photos.events.grace_days' => 3]);

        $past = Room::factory()->for(Event::factory()->state(['expires_at' => now()->subDays(4)]))->create();
        $inGrace = Room::factory()->for(Event::factory()->state(['expires_at' => now()->subDays(2)]))->create();
        $forever = Room::factory()->for(Event::factory()->state(['expires_at' => null]))->create();
        Photo::factory()->for($past)->create();
        $pastEvent = $past->event;

        $this->artisan('app:prune-expired-events')->assertSuccessful();

        $this->assertModelMissing($pastEvent);
        $this->assertModelExists($inGrace->event);
        $this->assertModelExists($forever->event);
    }

    public function test_dry_run_only_lists_expired_events(): void
    {
        $past = Room::factory()->for(Event::factory()->state(['name' => 'Stara žurka', 'expires_at' => now()->subDays(10)]))->create();

        $this->artisan('app:prune-expired-events', ['--dry-run' => true])
            ->expectsOutputToContain('Stara žurka')
            ->assertSuccessful();

        $this->assertModelExists($past->event);
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['X-Admin-Token' => self::ADMIN_TOKEN];
    }
}
