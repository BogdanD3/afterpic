<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_lists_only_processed_photos_newest_first(): void
    {
        $older = Photo::factory()->create(['created_at' => now()->subMinute()]);
        Photo::factory()->pending()->create();
        Photo::factory()->failed()->create();
        $newer = Photo::factory()->create();

        $response = $this->getJson('/api/photos');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.uuid', $newer->uuid);
        $response->assertJsonPath('data.1.uuid', $older->uuid);
    }

    public function test_photos_uploaded_in_the_same_second_are_still_paged_without_gaps_or_repeats(): void
    {
        config(['photos.per_page' => 2]);
        $this->freezeSecond();
        $created = Photo::factory()->count(5)->create();

        $seen = [];
        $path = '/api/photos';

        while ($path !== null) {
            $response = $this->getJson($path)->assertOk();
            $seen = [...$seen, ...collect($response->json('data'))->pluck('uuid')->all()];
            $path = $response->json('links.next');
        }

        $this->assertCount(5, $seen);
        $this->assertEqualsCanonicalizing($created->pluck('uuid')->all(), $seen);
    }

    public function test_gallery_returns_24_photos_per_page_with_a_relative_next_link(): void
    {
        Photo::factory()->count(30)->create();

        $response = $this->getJson('/api/photos');

        $response->assertOk()->assertJsonCount(24, 'data');

        // A path, never an absolute URL: behind a proxy the host would be wrong.
        $this->assertStringStartsWith('/api/photos?cursor=', $response->json('links.next'));
        $this->assertSame('/api/photos', $response->json('meta.path'));

        $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_page_cursor_contains_no_internal_id(): void
    {
        Photo::factory()->count(3)->create();
        config(['photos.per_page' => 1]);

        $cursor = $this->getJson('/api/photos')->json('meta.next_cursor');
        $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/')), true);

        $this->assertArrayNotHasKey('id', $decoded);
        $this->assertArrayHasKey('uuid', $decoded);
    }

    public function test_gallery_resource_exposes_public_fields_only(): void
    {
        Photo::factory()->create();

        $photo = $this->getJson('/api/photos')->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['uuid', 'thumb_url', 'web_url', 'width', 'height', 'created_at'],
            array_keys($photo),
        );
    }

    public function test_single_processed_photo_can_be_shown_by_uuid(): void
    {
        $photo = Photo::factory()->create();

        $this->getJson("/api/photos/{$photo->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $photo->uuid);
    }

    public function test_single_photo_that_is_not_processed_returns_404(): void
    {
        $photo = Photo::factory()->pending()->create();

        $this->getJson("/api/photos/{$photo->uuid}")->assertNotFound();
    }

    public function test_unknown_or_malformed_uuid_returns_404(): void
    {
        $this->getJson('/api/photos/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->getJson('/api/photos/not-a-uuid')->assertNotFound();
    }

    public function test_updates_return_only_processed_photos_after_the_given_id(): void
    {
        $first = Photo::factory()->create();
        $second = Photo::factory()->create();
        Photo::factory()->pending()->create();
        $third = Photo::factory()->create();

        $response = $this->getJson($this->updatesUrl($second->id, now()->addMinute()->timestamp));

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.uuid', $third->uuid);
        $this->assertNotSame($first->uuid, $response->json('data.0.uuid'));
    }

    public function test_updates_require_a_cursor(): void
    {
        $this->getJson('/api/photos/updates')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('cursor');
    }

    public function test_updates_return_304_when_nothing_changed(): void
    {
        Photo::factory()->create();
        $url = $this->updatesUrl(0);

        $first = $this->getJson($url);
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson($url)
            ->assertStatus(304);
    }

    public function test_responses_include_security_headers(): void
    {
        $this->getJson('/api/photos')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
