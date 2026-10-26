<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ModerationRequest;
use App\Models\Photo;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModerationRequestTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'test-admin-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config(['photos.admin_token' => self::ADMIN_TOKEN]);
    }

    public function test_any_guest_asks_for_a_photo_to_be_removed(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/photos/{$photo->uuid}/removal-request", ['reason' => 'To sam ja.'])->assertCreated();

        $this->assertDatabaseHas('moderation_requests', [
            'type' => ModerationRequest::TYPE_REMOVE_PHOTO,
            'photo_id' => $photo->id,
            'reason' => 'To sam ja.',
            'status' => ModerationRequest::STATUS_OPEN,
        ]);
        $this->assertModelExists($photo);
    }

    public function test_a_reason_is_required(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/photos/{$photo->uuid}/removal-request", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('reason');
    }

    public function test_requests_are_limited_per_phone(): void
    {
        $photo = Photo::factory()->create();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/photos/{$photo->uuid}/removal-request", ['reason' => 'x'])->assertCreated();
        }

        $this->postJson("/api/photos/{$photo->uuid}/removal-request", ['reason' => 'x'])->assertTooManyRequests();
    }

    public function test_only_the_host_or_the_room_admin_asks_for_a_room_to_be_deleted(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();

        $this->postJson("/api/rooms/{$guestRoom->code}/deletion-request", ['reason' => 'Gotovo.'])->assertUnauthorized();

        $this->postJson("/api/rooms/{$guestRoom->code}/deletion-request", ['reason' => 'Gotovo.'], ['X-Room-Key' => $guestRoom->admin_key])
            ->assertCreated();
        $this->postJson("/api/rooms/{$main->code}/deletion-request", ['reason' => 'Gotovo.'], ['X-Room-Key' => $main->event->host_key])
            ->assertCreated();

        $this->assertSame(2, ModerationRequest::where('type', ModerationRequest::TYPE_DELETE_ROOM)->count());
    }

    public function test_admin_sees_open_requests_with_their_context(): void
    {
        $request = ModerationRequest::factory()->create();

        $this->getJson('/api/admin/requests', $this->admin())
            ->assertOk()
            ->assertJsonPath('open_count', 1)
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.reason', 'To sam ja, molim uklonite.')
            ->assertJsonPath('data.0.photo.uuid', $request->photo->uuid);
    }

    public function test_approving_a_photo_request_deletes_the_photo_and_keeps_the_record(): void
    {
        $request = ModerationRequest::factory()->create();
        $photo = $request->photo;

        $this->postJson("/api/admin/requests/{$request->id}/approve", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('status', ModerationRequest::STATUS_APPROVED);

        $this->assertModelMissing($photo);
        $this->assertSame(ModerationRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertNull($request->fresh()->photo_id);
    }

    public function test_approving_a_guest_room_request_deletes_only_that_room(): void
    {
        $main = Room::factory()->create();
        $guestRoom = Room::factory()->guestRoom()->for($main->event)->create();
        $request = ModerationRequest::forRoom($guestRoom, ModerationRequest::TYPE_DELETE_ROOM, 'Gotovo.', null, null);

        $this->postJson("/api/admin/requests/{$request->id}/approve", [], $this->admin())->assertOk();

        $this->assertModelMissing($guestRoom);
        $this->assertModelExists($main);
        $this->assertSame($guestRoom->name, $request->fresh()->room_name);
    }

    public function test_approving_a_main_room_request_deletes_the_whole_event(): void
    {
        $main = Room::factory()->for(Event::factory())->create();
        $event = $main->event;
        $request = ModerationRequest::forRoom($main, ModerationRequest::TYPE_DELETE_ROOM, 'Gotovo.', null, null);

        $this->postJson("/api/admin/requests/{$request->id}/approve", [], $this->admin())->assertOk();

        $this->assertModelMissing($event);
    }

    public function test_rejecting_keeps_everything(): void
    {
        $request = ModerationRequest::factory()->create();

        $this->postJson("/api/admin/requests/{$request->id}/reject", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('status', ModerationRequest::STATUS_REJECTED);

        $this->assertModelExists($request->photo);
        $this->postJson("/api/admin/requests/{$request->id}/approve", [], $this->admin())->assertStatus(409);
    }

    public function test_the_inbox_needs_the_admin_token(): void
    {
        $this->getJson('/api/admin/requests')->assertUnauthorized();
    }

    public function test_admin_overview_numbers(): void
    {
        Photo::factory()->count(2)->create();
        ModerationRequest::factory()->create();

        $this->getJson('/api/admin/stats', $this->admin())
            ->assertOk()
            ->assertJsonPath('photos', 3)
            ->assertJsonPath('open_requests', 1)
            ->assertJsonPath('events_active', 1)
            ->assertJsonStructure(['disk' => ['total_bytes', 'free_bytes', 'used_percent']]);
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['X-Admin-Token' => self::ADMIN_TOKEN];
    }
}
