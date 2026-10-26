<?php

namespace Database\Factories;

use App\Models\ModerationRequest;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationRequest>
 */
class ModerationRequestFactory extends Factory
{
    /**
     * Define the model's default state: a guest asks for a photo to be removed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $photo = Photo::factory()->create();

        return [
            'type' => ModerationRequest::TYPE_REMOVE_PHOTO,
            'room_id' => $photo->room_id,
            'photo_id' => $photo->id,
            'room_name' => $photo->room->name,
            'event_name' => $photo->room->event->name,
            'reason' => 'To sam ja, molim uklonite.',
        ];
    }
}
