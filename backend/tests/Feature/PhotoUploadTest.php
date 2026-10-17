<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_valid_photo_is_accepted_and_processed(): void
    {
        $response = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ]);

        $response->assertAccepted()->assertJsonStructure(['uuid', 'status']);

        $photo = Photo::where('uuid', $response->json('uuid'))->firstOrFail();

        $this->assertSame(Photo::STATUS_PROCESSED, $photo->status);
        Storage::disk('local')->assertExists(Photo::downloadPathFor($photo->original_path));
        Storage::disk('local')->assertMissing($photo->original_path);
        Storage::disk('public')->assertExists($photo->web_path);
        Storage::disk('public')->assertExists($photo->thumb_path);
    }

    public function test_upload_is_stored_under_a_generated_path_not_the_client_filename(): void
    {
        $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('../../evil.jpg', 100, 100),
        ])->assertAccepted();

        $photo = Photo::firstOrFail();

        $this->assertStringStartsWith("photos/{$photo->uuid}/", $photo->original_path);
        $this->assertStringNotContainsString('evil', $photo->original_path);
    }

    public function test_photo_larger_than_fifty_megabytes_is_rejected(): void
    {
        $response = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->create('big.jpg', 51201, 'image/jpeg'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('photo');
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_heic_photo_is_rejected_with_a_clear_message(): void
    {
        $response = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->create('photo.heic', 10, 'image/heic'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrorFor('photo')
            ->assertJsonPath('errors.photo.0', 'Only JPEG, PNG and WebP photos are supported. HEIC photos must be converted first.');
    }

    public function test_non_image_file_with_image_extension_is_rejected(): void
    {
        $response = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->create('fake.jpg', 10, 'image/jpeg'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('photo');
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_photo_with_too_many_pixels_is_rejected_before_decoding(): void
    {
        config(['photos.max_pixels' => 100]);

        $response = $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('wide.jpg', 20, 20),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'The photo has too many pixels.');
    }

    public function test_upload_is_rate_limited_per_ip(): void
    {
        config(['photos.upload_rate_limit' => 2]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/photos', [
                'photo' => UploadedFile::fake()->image('photo.jpg', 50, 50),
            ])->assertAccepted();
        }

        $this->postJson('/api/photos', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 50, 50),
        ])->assertStatus(429);
    }

    public function test_missing_photo_field_is_rejected(): void
    {
        $this->postJson('/api/photos')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('photo');
    }
}
