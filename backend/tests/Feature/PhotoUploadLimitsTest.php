<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitPhotoUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PhotoUploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_first_upload_gets_a_signed_http_only_device_cookie(): void
    {
        $cookie = $this->upload()->assertAccepted()->getCookie(LimitPhotoUploads::COOKIE, decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api', $cookie->getPath());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.[0-9a-f]{64}$/', $cookie->getValue());
    }

    public function test_a_known_phone_does_not_get_a_new_cookie(): void
    {
        $device = $this->newDevice();

        $this->assertNull($this->upload($device)->assertAccepted()->getCookie(LimitPhotoUploads::COOKIE, decrypt: false));
    }

    public function test_each_phone_on_a_shared_ip_has_its_own_hourly_limit(): void
    {
        config(['photos.upload_limits.per_device_per_hour' => 2]);

        $first = $this->newDevice();
        $this->upload($first)->assertAccepted()->assertHeader('X-RateLimit-Remaining', '0');
        $this->upload($first)->assertTooManyRequests()->assertJsonStructure(['message', 'retry_after']);

        $second = $this->newDevice();
        $this->upload($second)->assertAccepted();
    }

    public function test_uploading_faster_than_a_person_blocks_the_phone_for_fifteen_minutes(): void
    {
        config(['photos.upload_limits.burst' => 3]);

        $device = $this->newDevice();
        $this->upload($device)->assertAccepted();
        $this->upload($device)->assertAccepted();

        $this->upload($device)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '900')
            ->assertJsonPath('retry_after', 900);

        // The block outlasts the 5 minute burst window.
        $this->travel(14)->minutes();
        $this->upload($device)->assertTooManyRequests();

        $this->travel(2)->minutes();
        $this->upload($device)->assertAccepted();
    }

    public function test_a_blocked_phone_does_not_block_other_phones(): void
    {
        config(['photos.upload_limits.burst' => 2]);

        $blocked = $this->newDevice();
        $this->upload($blocked)->assertAccepted();
        $this->upload($blocked)->assertTooManyRequests();

        // A second upload stays within the other phone's own burst of 2.
        $other = $this->newDevice();
        $this->upload($other)->assertAccepted();
    }

    public function test_a_second_burst_blocks_the_phone_for_an_hour(): void
    {
        config(['photos.upload_limits.burst' => 2]);

        $device = $this->newDevice();
        $this->upload($device)->assertAccepted();
        $this->upload($device)->assertTooManyRequests()->assertJsonPath('retry_after', 15 * 60);

        $this->travel(16)->minutes();
        $this->upload($device)->assertAccepted();
        $this->upload($device)->assertAccepted();
        $this->upload($device)->assertTooManyRequests()->assertJsonPath('retry_after', 60 * 60);
    }

    public function test_a_forged_device_cookie_is_treated_as_a_new_phone(): void
    {
        $forged = str_repeat('a', 40).'.'.str_repeat('0', 64);

        $cookie = $this->upload($forged)->assertAccepted()->getCookie(LimitPhotoUploads::COOKIE, decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertNotSame($forged, $cookie->getValue());
    }

    public function test_the_ip_safety_net_counts_all_phones_together(): void
    {
        config(['photos.upload_limits.per_ip_per_hour' => 3]);

        $first = $this->newDevice();
        $this->upload($first)->assertAccepted();
        $second = $this->newDevice();

        $this->upload($second)->assertTooManyRequests();
    }

    public function test_new_device_ids_are_limited_per_ip(): void
    {
        config(['photos.upload_limits.new_devices_per_ip_per_hour' => 2]);

        $device = $this->newDevice();
        $this->upload()->assertAccepted();
        $this->upload()->assertTooManyRequests();

        // Phones that already have an id keep uploading.
        $this->upload($device)->assertAccepted();
    }

    /**
     * Upload as a phone with the given device cookie, or as a new phone.
     *
     * @return TestResponse<Response>
     */
    private function upload(?string $device = null): TestResponse
    {
        $this->unencryptedCookies = $device === null ? [] : [LimitPhotoUploads::COOKIE => $device];

        return $this->withCredentials()->postJson($this->photosUrl(), [
            'photo' => UploadedFile::fake()->image('photo.jpg', 50, 50),
        ]);
    }

    /**
     * Make one upload as a new phone and return the device cookie it got.
     */
    private function newDevice(): string
    {
        $cookie = $this->upload()->assertAccepted()->getCookie(LimitPhotoUploads::COOKIE, decrypt: false);

        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }
}
