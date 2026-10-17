<?php

namespace App\Http\Resources;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Photo
 */
class PhotoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Internal ids and storage paths are never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'thumb_url' => Storage::disk('public')->url($this->thumb_path),
            'web_url' => Storage::disk('public')->url($this->web_path),
            'width' => $this->width,
            'height' => $this->height,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
