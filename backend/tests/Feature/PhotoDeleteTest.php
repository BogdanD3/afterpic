<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PhotoDeleteTest extends TestCase
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

    public function test_admin_can_delete_a_photo_with_its_row_and_both_folders(): void
    {
        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], $this->adminHeaders())
            ->assertNoContent();

        $this->assertDatabaseMissing('photos', ['uuid' => $photo->uuid]);
        Storage::disk('local')->assertMissing("photos/{$photo->uuid}");
        Storage::disk('public')->assertMissing("photos/{$photo->uuid}");
    }

    public function test_deleted_photo_is_no_longer_shown_in_the_gallery(): void
    {
        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], $this->adminHeaders())->assertNoContent();

        $this->getJson('/api/photos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/photos/{$photo->uuid}")->assertNotFound();
    }

    public function test_pending_and_failed_photos_can_be_deleted_too(): void
    {
        $pending = Photo::factory()->pending()->create();
        $failed = Photo::factory()->failed()->create();

        $this->deleteJson("/api/photos/{$pending->uuid}", [], $this->adminHeaders())->assertNoContent();
        $this->deleteJson("/api/photos/{$failed->uuid}", [], $this->adminHeaders())->assertNoContent();

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_delete_without_the_token_is_refused_and_nothing_is_deleted(): void
    {
        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}")->assertUnauthorized();

        $this->assertDatabaseHas('photos', ['uuid' => $photo->uuid]);
        Storage::disk('local')->assertExists($photo->original_path);
    }

    public function test_delete_with_a_wrong_token_is_refused(): void
    {
        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Admin-Token' => 'wrong-token'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('photos', ['uuid' => $photo->uuid]);
    }

    public function test_admin_routes_do_not_exist_when_no_admin_token_is_configured(): void
    {
        config(['photos.admin_token' => null]);

        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Admin-Token' => ''])
            ->assertNotFound();

        $this->assertDatabaseHas('photos', ['uuid' => $photo->uuid]);
    }

    public function test_unknown_or_malformed_uuid_returns_404(): void
    {
        $this->deleteJson('/api/photos/'.fake()->uuid(), [], $this->adminHeaders())->assertNotFound();
        $this->deleteJson('/api/photos/not-a-uuid', [], $this->adminHeaders())->assertNotFound();
    }

    public function test_row_is_kept_when_the_files_cannot_be_deleted(): void
    {
        $photo = $this->storedPhoto();

        Storage::shouldReceive('disk')
            ->with('local')
            ->andThrow(new RuntimeException('The storage volume is not writable.'));

        $this->deleteJson("/api/photos/{$photo->uuid}", [], $this->adminHeaders())
            ->assertInternalServerError();

        $this->assertDatabaseHas('photos', ['uuid' => $photo->uuid]);
    }

    public function test_admin_requests_are_rate_limited_per_ip(): void
    {
        config(['photos.admin_rate_limit' => 2]);

        $photo = $this->storedPhoto();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Admin-Token' => 'wrong-1'])->assertUnauthorized();
        $this->deleteJson("/api/photos/{$photo->uuid}", [], ['X-Admin-Token' => 'wrong-2'])->assertUnauthorized();

        $this->deleteJson("/api/photos/{$photo->uuid}", [], $this->adminHeaders())->assertStatus(429);
        $this->assertDatabaseHas('photos', ['uuid' => $photo->uuid]);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return ['X-Admin-Token' => self::ADMIN_TOKEN];
    }

    /**
     * A processed photo whose files really exist on the fake disks.
     */
    private function storedPhoto(): Photo
    {
        $photo = Photo::factory()->create();

        Storage::disk('local')->put($photo->original_path, 'original');
        Storage::disk('public')->put("photos/{$photo->uuid}/web.webp", 'web');

        return $photo;
    }
}
