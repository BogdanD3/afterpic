<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One gallery with its own link (/r/{code}).
 *
 * The code is the key: it is the room's name plus a random part, so it can
 * be recognised but not guessed, and whoever has the link can view and
 * upload. Every event has one main room; guest rooms exist only when the
 * event allows them, and their creator gets `admin_key` to manage them.
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    private const RANDOM_PART_LENGTH = 10;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'code',
        'name',
        'is_main',
        'admin_key',
        'created_by_device',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['admin_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'admin_key' => 'encrypted',
            'deleting_at' => 'datetime',
        ];
    }

    /**
     * A new, unused room code such as "rodjendan-k7m2q9xw4p".
     */
    public static function generateCode(string $name): string
    {
        $slug = Str::limit(Str::slug($name), 24, '') ?: 'soba';
        $slug = rtrim($slug, '-');

        do {
            $code = $slug.'-'.Str::lower(Str::random(self::RANDOM_PART_LENGTH));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * Rooms that are not being deleted, in events that are not being deleted.
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleting_at')->whereHas('event', fn (Builder $event) => $event->whereNull('deleting_at'));
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Route parameters only ever find rooms that can still be opened.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::active()->with('event')->where($field ?? 'code', $value)->first();
    }
}
