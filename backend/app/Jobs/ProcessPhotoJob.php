<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPhotoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public Photo $photo) {}

    /**
     * Execute the job.
     */
    public function handle(ImageProcessor $processor): void
    {
        $originalPath = Storage::disk('local')->path($this->photo->original_path);

        $result = $processor->process($originalPath);

        $directory = dirname($this->photo->original_path);
        $webPath = "{$directory}/web.webp";
        $thumbPath = "{$directory}/thumb.webp";

        Storage::disk('public')->put($webPath, $result['web']);
        Storage::disk('public')->put($thumbPath, $result['thumb']);
        Storage::disk('local')->put(Photo::downloadPathFor($this->photo->original_path), $result['download']);

        $this->photo->update([
            'status' => Photo::STATUS_PROCESSED,
            'web_path' => $webPath,
            'thumb_path' => $thumbPath,
            'width' => $result['width'],
            'height' => $result['height'],
            'web_size_bytes' => strlen($result['web']),
        ]);

        $this->removeOriginalUnlessKept();
    }

    /**
     * Delete the raw upload once the download copy is safely stored.
     *
     * A failure here is only logged: the photo is already fully usable.
     */
    private function removeOriginalUnlessKept(): void
    {
        if (config('photos.download.keep_originals')) {
            return;
        }

        try {
            Storage::disk('local')->delete($this->photo->original_path);
        } catch (Throwable $exception) {
            Log::warning('Could not delete the original after processing', [
                'photo_uuid' => $this->photo->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $this->photo->update(['status' => Photo::STATUS_FAILED]);

        Log::error('Photo processing failed', [
            'photo_uuid' => $this->photo->uuid,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
