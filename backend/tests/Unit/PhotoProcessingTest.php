<?php

namespace Tests\Unit;

use App\Jobs\ProcessPhotoJob;
use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PhotoProcessingTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'photo-tests-'.uniqid();
        mkdir($this->tempDirectory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDirectory.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($this->tempDirectory);

        parent::tearDown();
    }

    public function test_web_image_is_scaled_down_to_1600_px_and_encoded_as_webp(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(3000, 2000));

        // Reported dimensions are those of the web image, not of the upload.
        $this->assertSame(1600, $result['width']);
        $this->assertSame(1067, $result['height']);
        $this->assertSame([1600, 1067], $this->dimensionsOf($result['web']));
        $this->assertSame(IMAGETYPE_WEBP, $this->imageTypeOf($result['web']));
    }

    public function test_thumbnail_is_a_400_by_400_square_webp(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(3000, 2000));

        $this->assertSame([400, 400], $this->dimensionsOf($result['thumb']));
        $this->assertSame(IMAGETYPE_WEBP, $this->imageTypeOf($result['thumb']));
    }

    public function test_small_images_are_not_upscaled(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(200, 100));

        $this->assertSame([200, 100], $this->dimensionsOf($result['web']));
    }

    public function test_exif_orientation_is_applied_before_encoding(): void
    {
        // Orientation 6 means the camera was rotated 90 degrees, so a 300x100
        // landscape file must come out as a 100x300 portrait image.
        $result = (new ImageProcessor)->process($this->jpegFile(300, 100, orientation: 6));

        $this->assertSame(100, $result['width']);
        $this->assertSame(300, $result['height']);
        $this->assertSame([100, 300], $this->dimensionsOf($result['web']));
    }

    public function test_metadata_such_as_camera_make_and_gps_is_removed(): void
    {
        $path = $this->jpegFile(300, 200, withMetadata: true);

        $this->assertArrayHasKey('Make', exif_read_data($path));

        $result = (new ImageProcessor)->process($path);

        $this->assertStringNotContainsString('Cam', $result['web']);
        $this->assertStringNotContainsString('Exif', $result['web']);
        $this->assertStringNotContainsString('Cam', $result['thumb']);
    }

    public function test_download_copy_is_a_jpeg_scaled_down_to_3000_px(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(4000, 3000));

        $this->assertSame([3000, 2250], $this->dimensionsOf($result['download']));
        $this->assertSame(IMAGETYPE_JPEG, $this->imageTypeOf($result['download']));
    }

    public function test_small_images_are_not_upscaled_for_the_download_copy(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(800, 600));

        $this->assertSame([800, 600], $this->dimensionsOf($result['download']));
    }

    public function test_download_copy_has_no_camera_or_gps_metadata_and_is_upright(): void
    {
        $result = (new ImageProcessor)->process($this->jpegFile(300, 100, orientation: 6, withMetadata: true));

        $this->assertSame([100, 300], $this->dimensionsOf($result['download']));
        $this->assertStringNotContainsString('Cam', $result['download']);
        $this->assertStringNotContainsString('Exif', $result['download']);
    }

    public function test_download_copy_is_not_larger_than_the_source(): void
    {
        $path = $this->jpegFile(3000, 2000);
        $result = (new ImageProcessor)->process($path);

        $this->assertLessThanOrEqual(filesize($path), strlen($result['download']));
    }

    public function test_failed_job_marks_the_photo_as_failed(): void
    {
        $photo = Photo::factory()->pending()->create();

        (new ProcessPhotoJob($photo))->failed(new RuntimeException('decode error'));

        $this->assertSame(Photo::STATUS_FAILED, $photo->fresh()->status);
    }

    /**
     * Create a JPEG on disk, optionally with EXIF orientation and metadata.
     */
    private function jpegFile(int $width, int $height, int $orientation = 1, bool $withMetadata = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));

        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        if ($withMetadata || $orientation !== 1) {
            $jpeg = substr($jpeg, 0, 2).$this->exifSegment($orientation).substr($jpeg, 2);
        }

        $path = $this->tempDirectory.DIRECTORY_SEPARATOR.uniqid('source', true).'.jpg';
        file_put_contents($path, $jpeg);

        return $path;
    }

    /**
     * Build a minimal APP1/EXIF segment (little endian) with Make, Orientation and a GPS pointer.
     */
    private function exifSegment(int $orientation): string
    {
        $ifd0Entries = 3;
        $gpsOffset = 8 + 2 + ($ifd0Entries * 12) + 4;

        $tiff = 'II'.pack('vV', 42, 8)
            .pack('v', $ifd0Entries)
            .pack('vvV', 0x010F, 2, 4).'Cam'."\0"
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0"
            .pack('vvV', 0x8825, 4, 1).pack('V', $gpsOffset)
            .pack('V', 0)
            .pack('v', 1)
            .pack('vvV', 0x0000, 1, 4)."\x02\x03\x00\x00"
            .pack('V', 0);

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensionsOf(string $imageBytes): array
    {
        $size = getimagesizefromstring($imageBytes);

        return [$size[0], $size[1]];
    }

    private function imageTypeOf(string $imageBytes): int
    {
        return getimagesizefromstring($imageBytes)[2];
    }
}
