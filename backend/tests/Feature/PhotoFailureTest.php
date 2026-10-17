<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhotoFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_disks_raise_write_errors_instead_of_hiding_them(): void
    {
        $this->assertTrue(config('filesystems.disks.local.throw'));
        $this->assertTrue(config('filesystems.disks.public.throw'));
    }

    public function test_upload_returns_503_and_creates_no_photo_when_the_disk_cannot_be_written(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(new RuntimeException('No space left on device'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 400, 300),
        ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The server could not store the photo.');

        $this->assertSame(0, Photo::count());
    }

    public function test_upload_returns_503_when_the_disk_reports_a_failed_write(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 400, 300),
        ])->assertStatus(503);

        $this->assertSame(0, Photo::count());
    }

    public function test_status_reports_each_processing_state(): void
    {
        $pending = Photo::factory()->pending()->create();
        $processed = Photo::factory()->create();
        $failed = Photo::factory()->failed()->create();

        $this->getJson("/api/photos/{$pending->uuid}/status")->assertOk()->assertExactJson(['status' => 'pending']);
        $this->getJson("/api/photos/{$processed->uuid}/status")->assertOk()->assertExactJson(['status' => 'processed']);
        $this->getJson("/api/photos/{$failed->uuid}/status")->assertOk()->assertExactJson(['status' => 'failed']);
    }

    public function test_status_of_unknown_or_malformed_uuid_returns_404(): void
    {
        $this->getJson('/api/photos/'.fake()->uuid().'/status')->assertNotFound();
        $this->getJson('/api/photos/not-a-uuid/status')->assertNotFound();
    }

    public function test_validation_failures_use_422_with_a_message_for_the_photo_field(): void
    {
        Storage::fake('local');

        $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('photo');

        $this->postJson('/api/photos', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('photo');
    }
}
