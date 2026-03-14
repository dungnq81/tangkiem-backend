<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadingHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'story' => new StoryResource($this->whenLoaded('story')),
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
            'progress' => $this->progress,
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
