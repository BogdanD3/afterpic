<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'status',
        'original_path',
        'web_path',
        'thumb_path',
        'width',
        'height',
        'web_size_bytes',
        'mime_type',
    ];

    /**
     * Only photos that are ready to be shown publicly.
     *
     * @param  Builder<Photo>  $query
     * @return Builder<Photo>
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Where the JPEG download copy lives, next to the original (private disk).
     */
    public static function downloadPathFor(string $originalPath): string
    {
        return dirname($originalPath).'/download.jpg';
    }

    /**
     * Remove both photo folders, then the row.
     *
     * The row goes last, so a photo whose files could not be removed is still
     * in the database and can be deleted again (by the nightly prune or an admin)
     * instead of leaving orphan files behind.
     */
    public function deleteWithFiles(): bool
    {
        $directory = dirname($this->original_path);

        try {
            Storage::disk('local')->deleteDirectory($directory);
            Storage::disk('public')->deleteDirectory($directory);
            $this->delete();
        } catch (Throwable $exception) {
            Log::error('Could not delete a photo', [
                'photo_uuid' => $this->uuid,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
