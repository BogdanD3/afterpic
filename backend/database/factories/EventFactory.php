<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Rođendan',
            'guest_rooms_enabled' => false,
            'uploads_open' => true,
            'expires_at' => now()->addDays(30),
            'host_key' => Str::random(32),
        ];
    }

    /**
     * Event whose guests may create their own rooms.
     */
    public function withGuestRooms(): static
    {
        return $this->state(fn (): array => ['guest_rooms_enabled' => true]);
    }

    /**
     * Event past its expiry date: uploads are closed.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
