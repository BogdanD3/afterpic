<?php

namespace Database\Factories;

use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'status' => Photo::STATUS_PROCESSED,
            'original_path' => "photos/{$uuid}/original",
            'web_path' => "photos/{$uuid}/web.webp",
            'thumb_path' => "photos/{$uuid}/thumb.webp",
            'width' => 1600,
            'height' => 1200,
            'web_size_bytes' => 150_000,
            'mime_type' => 'image/jpeg',
        ];
    }

    /**
     * Photo that is still waiting for the queue worker.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => Photo::STATUS_PENDING,
            'web_path' => null,
            'thumb_path' => null,
            'width' => null,
            'height' => null,
            'web_size_bytes' => null,
        ]);
    }

    /**
     * Photo whose processing failed.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => Photo::STATUS_FAILED,
            'web_path' => null,
            'thumb_path' => null,
        ]);
    }
}
