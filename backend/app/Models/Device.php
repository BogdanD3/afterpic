<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

/**
 * A phone, known by the id in its signed cookie (see DeviceIdentity).
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'name', 'client', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The device record for an id, created on first sight, with when and
     * on what it was last seen.
     */
    public static function seen(string $id, Request $request): self
    {
        $device = self::firstOrNew(['id' => $id]);
        $device->fill(['client' => self::describeClient((string) $request->userAgent()), 'last_seen_at' => now()]);
        $device->save();

        return $device;
    }

    public static function isBlocked(string $id): bool
    {
        return self::whereKey($id)->whereNotNull('blocked_at')->exists();
    }

    /**
     * "iPhone · Safari", "Android · Chrome" and so on. Only this short
     * description is kept, never the full user agent.
     */
    public static function describeClient(string $userAgent): ?string
    {
        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Instagram') => 'Instagram',
            str_contains($userAgent, 'FBAN') || str_contains($userAgent, 'FBAV') => 'Facebook',
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        $parts = array_filter([$platform, $browser]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}
