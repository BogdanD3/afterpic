<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhotoLiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_with_a_lower_id_that_finished_later_is_returned_through_since(): void
    {
        // Photo 1 was uploaded first but finished processing AFTER photo 2.
        $late = Photo::factory()->create(['updated_at' => now()]);
        $seen = Photo::factory()->create(['updated_at' => now()->subSeconds(20)]);

        $response = $this->getJson($this->updatesUrl($seen->id, now()->subSeconds(60)->timestamp));

        $response->assertOk();
        $this->assertContains($late->uuid, collect($response->json('data'))->pluck('uuid')->all());
    }

    public function test_photos_that_finished_before_since_and_have_a_low_id_are_not_returned(): void
    {
        $old = Photo::factory()->create(['updated_at' => now()->subHour()]);

        $this->getJson($this->updatesUrl($old->id, now()->subMinute()->timestamp))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pending_and_failed_photos_are_never_returned_through_since(): void
    {
        Photo::factory()->pending()->create(['updated_at' => now()]);
        Photo::factory()->failed()->create(['updated_at' => now()]);

        $this->getJson($this->updatesUrl(999, 0))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_response_contains_the_next_cursor_and_has_more_flag(): void
    {
        Photo::factory()->create();

        $this->getJson($this->updatesUrl(0))
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonStructure(['data', 'cursor', 'has_more']);
    }

    public function test_next_cursor_moves_past_the_returned_photos_and_since_moves_to_now(): void
    {
        $this->freezeSecond();
        $first = Photo::factory()->create();
        $second = Photo::factory()->create();

        $cursor = $this->nextCursor($this->getJson($this->updatesUrl(0, 100)));

        $this->assertSame($second->id, $cursor['after_id']);
        $this->assertSame(now()->timestamp - 30, $cursor['since']);
        $this->assertGreaterThan($first->id, $cursor['after_id']);
    }

    public function test_next_cursor_keeps_since_when_the_limit_cuts_the_list_short(): void
    {
        config(['photos.updates_limit' => 2]);
        $photos = Photo::factory()->count(3)->create();

        $response = $this->getJson($this->updatesUrl(0, 100))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', true);

        $cursor = $this->nextCursor($response);

        $this->assertSame($photos[1]->id, $cursor['after_id']);
        $this->assertSame(100, $cursor['since']);
    }

    public function test_next_cursor_keeps_after_id_when_nothing_new_is_returned(): void
    {
        $cursor = $this->nextCursor($this->getJson($this->updatesUrl(42, 0)));

        $this->assertSame(42, $cursor['after_id']);
    }

    public function test_missing_tampered_or_forged_cursor_is_rejected(): void
    {
        $this->getJson('/api/photos/updates')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('cursor');

        $this->getJson('/api/photos/updates?cursor=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('cursor');

        // A guest cannot build a cursor: plain JSON is not accepted.
        $this->getJson('/api/photos/updates?cursor='.urlencode(base64_encode('{"after_id":0,"since":0}')))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('cursor');

        // Encrypted, but not the expected shape.
        $this->getJson('/api/photos/updates?cursor='.urlencode(Crypt::encryptString('{"after_id":"x"}')))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('cursor');
    }

    public function test_polled_photos_contain_no_internal_id(): void
    {
        Photo::factory()->create();

        $photo = $this->getJson($this->updatesUrl(0))->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['uuid', 'thumb_url', 'web_url', 'width', 'height', 'created_at'],
            array_keys($photo),
        );
    }

    public function test_etag_changes_when_a_photo_finishes_without_raising_the_latest_id(): void
    {
        $photo = Photo::factory()->create(['updated_at' => now()->subMinute()]);

        $before = $this->getJson($this->updatesUrl(999))->headers->get('ETag');

        $photo->forceFill(['updated_at' => now()])->save();

        $after = $this->getJson($this->updatesUrl(999))->headers->get('ETag');

        $this->assertNotSame($before, $after);
    }

    public function test_gallery_page_contains_the_cursor_for_the_first_poll(): void
    {
        $this->freezeSecond();
        Photo::factory()->create();
        $newest = Photo::factory()->create();
        Photo::factory()->pending()->create();

        $response = $this->getJson('/api/photos')->assertOk();

        $this->assertArrayNotHasKey('latest_id', $response->json());

        $cursor = json_decode(Crypt::decryptString($response->json('updates_cursor')), true);

        $this->assertSame(['after_id' => $newest->id, 'since' => now()->timestamp - 30], $cursor);
    }

    /**
     * @return array{after_id: int, since: int}
     */
    private function nextCursor(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode(Crypt::decryptString($response->json('cursor')), true);
    }
}
