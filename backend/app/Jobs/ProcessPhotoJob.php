<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

    /**
     * A photo deleted before the job starts needs no processing.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Photo $photo) {}

    /**
     * Execute the job.
     */
    public function handle(ImageProcessor $processor): void
    {
        $originalPath = Storage::disk('local')->path($this->photo->original_path);

        $result = $processor->process($originalPath);

        if ($this->storeResult($result)) {
            $this->removeOriginalUnlessKept();
        }
    }

    /**
     * Write the files and mark the photo processed, unless it was deleted
     * while it was being processed. The row lock is the same one
     * Photo::deleteWithFiles takes, so a delete waits for this or the other
     * way round, and files are never written for a deleted photo.
     *
     * @param  array{width: int, height: int, web: string, thumb: string, download: string}  $result
     */
    private function storeResult(array $result): bool
    {
        return DB::transaction(function () use ($result): bool {
            if (! Photo::whereKey($this->photo->getKey())->lockForUpdate()->exists()) {
                return false;
            }

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

            return true;
        });
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
