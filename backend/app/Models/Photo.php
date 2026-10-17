<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
