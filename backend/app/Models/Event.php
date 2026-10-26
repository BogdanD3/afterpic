<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One booked party. Only the super admin creates events.
 *
 * `expires_at` closes uploads; the host can still view and download for
 * photos.events.grace_days, then the nightly app:prune-expired-events
 * deletes the whole event. Null means it never expires.
 *
 * `host_key` is the secret in the host's admin link: it manages every room
 * of this event (delete photos, request a room deletion).
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guest_rooms_enabled',
        'uploads_open',
        'expires_at',
        'host_key',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['host_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_rooms_enabled' => 'boolean',
            'uploads_open' => 'boolean',
            'expires_at' => 'datetime',
            'deleting_at' => 'datetime',
            'host_key' => 'encrypted',
        ];
    }

    /**
     * Create an event together with its main room.
     *
     * @param  int|null  $days  Days until expiry; null uses photos.events.default_days, 0 never expires.
     */
    public static function createWithMainRoom(string $name, ?int $days = null, bool $guestRoomsEnabled = false): self
    {
        $days ??= (int) config('photos.events.default_days');

        return DB::transaction(function () use ($name, $days, $guestRoomsEnabled): self {
            $event = self::create([
                'name' => $name,
                'guest_rooms_enabled' => $guestRoomsEnabled,
                'expires_at' => $days > 0 ? now()->addDays($days) : null,
                'host_key' => Str::random(32),
            ]);

            $event->rooms()->create([
                'code' => Room::generateCode($name),
                'name' => $name,
                'is_main' => true,
            ]);

            return $event;
        });
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return HasOne<Room, $this>
     */
    public function mainRoom(): HasOne
    {
        return $this->hasOne(Room::class)->where('is_main', true);
    }

    /**
     * Events that are not being deleted.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleting_at');
    }

    public function acceptsUploads(): bool
    {
        return $this->uploads_open && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * When the nightly clean-up deletes the event, or null when it never expires.
     */
    public function deletesAt(): ?CarbonInterface
    {
        return $this->expires_at?->copy()->addDays((int) config('photos.events.grace_days'));
    }
}
