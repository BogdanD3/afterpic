<?php

namespace Tests\Feature;

use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_pending_photos_are_marked_as_failed(): void
    {
        $stale = Photo::factory()->pending()->create(['created_at' => now()->subHours(2)]);
        $fresh = Photo::factory()->pending()->create(['created_at' => now()->subMinutes(5)]);

        $this->artisan('app:fail-stale-photos')->assertSuccessful();

        $this->assertSame(Photo::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(Photo::STATUS_PENDING, $fresh->fresh()->status);
    }

    public function test_environment_check_passes_on_a_gd_build_with_webp(): void
    {
        $this->artisan('app:check-photo-environment')->assertSuccessful();
    }

    public function test_photos_older_than_the_retention_period_are_deleted_with_their_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['photos.retention.days' => 30]);

        $old = $this->photoWithFiles(now()->subDays(31));
        $oldFailed = $this->photoWithFiles(now()->subDays(40), Photo::factory()->failed());
        $recent = $this->photoWithFiles(now()->subDays(29));

        $this->artisan('app:prune-old-photos')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($oldFailed);
        $this->assertModelExists($recent);

        foreach ([$old, $oldFailed] as $photo) {
            Storage::disk('local')->assertMissing("photos/{$photo->uuid}");
            Storage::disk('public')->assertMissing("photos/{$photo->uuid}");
        }

        Storage::disk('local')->assertExists("photos/{$recent->uuid}/download.jpg");
        Storage::disk('public')->assertExists("photos/{$recent->uuid}/web.webp");
    }

    public function test_dry_run_lists_old_photos_without_deleting_anything(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $old = $this->photoWithFiles(now()->subDays(60));

        $this->artisan('app:prune-old-photos', ['--dry-run' => true])
            ->expectsOutputToContain($old->uuid)
            ->expectsOutputToContain('1 photo(s) older than 30 days would be deleted.')
            ->assertSuccessful();

        $this->assertModelExists($old);
        Storage::disk('public')->assertExists("photos/{$old->uuid}/web.webp");
    }

    public function test_nothing_is_deleted_when_retention_is_turned_off(): void
    {
        config(['photos.retention.enabled' => false]);

        $old = Photo::factory()->create(['created_at' => now()->subYear()]);

        $this->artisan('app:prune-old-photos')->assertSuccessful();

        $this->assertModelExists($old);
    }

    public function test_retention_of_zero_days_is_refused_instead_of_deleting_everything(): void
    {
        config(['photos.retention.days' => 0]);

        $photo = Photo::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('app:prune-old-photos')->assertFailed();

        $this->assertModelExists($photo);
    }

    public function test_disk_check_warns_when_usage_reaches_the_threshold(): void
    {
        config(['photos.disk_warning_percent' => 0]);
        Log::spy();

        $this->artisan('app:check-disk-space')->assertFailed();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => $message === 'Photo storage disk is almost full'
        );
    }

    public function test_disk_check_passes_below_the_threshold(): void
    {
        config(['photos.disk_warning_percent' => 101]);

        $this->artisan('app:check-disk-space')->assertSuccessful();
    }

    /**
     * Processed photo with all three stored files on the fake disks.
     *
     * @param  Factory<Photo>|null  $factory
     */
    private function photoWithFiles(Carbon $createdAt, ?Factory $factory = null): Photo
    {
        $photo = ($factory ?? Photo::factory())->create(['created_at' => $createdAt]);

        Storage::disk('local')->put(Photo::downloadPathFor($photo->original_path), 'download');
        Storage::disk('public')->put("photos/{$photo->uuid}/web.webp", 'web');
        Storage::disk('public')->put("photos/{$photo->uuid}/thumb.webp", 'thumb');

        return $photo;
    }
}
