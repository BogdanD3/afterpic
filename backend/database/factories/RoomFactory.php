<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state: the main room of a new event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'code' => 'soba-'.Str::lower(Str::random(10)),
            'name' => 'Rođendan',
            'is_main' => true,
        ];
    }

    /**
     * Room a guest created, with its own admin key.
     */
    public function guestRoom(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Ekipa',
            'is_main' => false,
            'admin_key' => Str::random(32),
            'created_by_device' => Str::random(40),
        ]);
    }
}
