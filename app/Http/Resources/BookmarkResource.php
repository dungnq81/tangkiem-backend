<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookmarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'story' => new StoryResource($this->whenLoaded('story')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
