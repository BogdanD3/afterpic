<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_original_of_an_uploaded_photo_is_downloaded_unchanged_when_originals_are_kept(): void
    {
        config(['photos.download.keep_originals' => true]);

        $upload = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ])->assertAccepted();

        $photo = Photo::where('uuid', $upload->json('uuid'))->firstOrFail();

        $response = $this->get("/api/photos/{$photo->uuid}/download");

        $response->assertOk();
        $this->assertSame(
            Storage::disk('local')->get($photo->original_path),
            $response->streamedContent(),
        );
    }

    public function test_by_default_a_large_upload_is_downloaded_as_a_smaller_jpeg_and_the_original_is_deleted(): void
    {
        $upload = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('big.png', 4000, 3000),
        ])->assertAccepted();

        $photo = Photo::where('uuid', $upload->json('uuid'))->firstOrFail();

        Storage::disk('local')->assertMissing($photo->original_path);
        Storage::disk('local')->assertExists(Photo::downloadPathFor($photo->original_path));

        $response = $this->get("/api/photos/{$photo->uuid}/download")->assertOk();
        $bytes = $response->streamedContent();
        $size = getimagesizefromstring($bytes);

        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame([3000, 2250], [$size[0], $size[1]]);
        $this->assertStringContainsString('.jpg', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_download_copy_is_preferred_over_the_original_when_both_exist(): void
    {
        $photo = $this->storedPhoto('image/png');
        Storage::disk('local')->put(Photo::downloadPathFor($photo->original_path), 'download-copy');

        $response = $this->get("/api/photos/{$photo->uuid}/download")->assertOk();

        $this->assertSame('download-copy', $response->streamedContent());
        $this->assertStringContainsString('.jpg', $response->headers->get('Content-Disposition'));
    }

    public function test_original_is_served_when_originals_are_kept_even_if_a_download_copy_exists(): void
    {
        config(['photos.download.keep_originals' => true]);

        $photo = $this->storedPhoto('image/png');
        Storage::disk('local')->put(Photo::downloadPathFor($photo->original_path), 'download-copy');

        $response = $this->get("/api/photos/{$photo->uuid}/download")->assertOk();

        $this->assertSame('original-bytes-'.$photo->uuid, $response->streamedContent());
        $this->assertStringContainsString('.png', $response->headers->get('Content-Disposition'));
    }

    public function test_download_is_sent_as_an_attachment_with_a_generated_file_name(): void
    {
        $photo = $this->storedPhoto('image/jpeg');

        $response = $this->get("/api/photos/{$photo->uuid}/download");

        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('foto-', $disposition);
        $this->assertStringContainsString('.jpg', $disposition);
        $this->assertStringContainsString(substr($photo->uuid, 0, 8), $disposition);
        $this->assertStringContainsString('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_file_extension_follows_the_stored_mime_type(): void
    {
        $png = $this->storedPhoto('image/png');
        $webp = $this->storedPhoto('image/webp');

        $this->assertStringContainsString(
            '.png',
            $this->get("/api/photos/{$png->uuid}/download")->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            '.webp',
            $this->get("/api/photos/{$webp->uuid}/download")->headers->get('Content-Disposition'),
        );
    }

    public function test_pending_photo_cannot_be_downloaded(): void
    {
        $photo = Photo::factory()->pending()->create();
        Storage::disk('local')->put($photo->original_path, 'original');

        $this->get("/api/photos/{$photo->uuid}/download")->assertNotFound();
    }

    public function test_failed_photo_cannot_be_downloaded(): void
    {
        $photo = Photo::factory()->failed()->create();
        Storage::disk('local')->put($photo->original_path, 'original');

        $this->get("/api/photos/{$photo->uuid}/download")->assertNotFound();
    }

    public function test_unknown_or_malformed_uuid_returns_404(): void
    {
        $this->get('/api/photos/'.fake()->uuid().'/download')->assertNotFound();
        $this->get('/api/photos/not-a-uuid/download')->assertNotFound();
    }

    public function test_missing_original_file_returns_404_instead_of_an_error(): void
    {
        $photo = Photo::factory()->create();

        $this->get("/api/photos/{$photo->uuid}/download")->assertNotFound();
    }

    public function test_download_is_rate_limited_per_ip(): void
    {
        config(['photos.download_rate_limit' => 2]);

        $photo = $this->storedPhoto('image/jpeg');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->get("/api/photos/{$photo->uuid}/download")->assertOk();
        }

        $this->get("/api/photos/{$photo->uuid}/download")->assertStatus(429);
    }

    public function test_download_does_not_change_the_gallery_endpoints(): void
    {
        $photo = $this->storedPhoto('image/jpeg');

        $this->getJson("/api/photos/{$photo->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $photo->uuid);
    }

    /**
     * A processed photo whose original file really exists on the fake disk.
     */
    private function storedPhoto(string $mimeType): Photo
    {
        $photo = Photo::factory()->create(['mime_type' => $mimeType]);

        Storage::disk('local')->put($photo->original_path, 'original-bytes-'.$photo->uuid);

        return $photo;
    }
}
