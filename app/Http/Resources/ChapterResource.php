<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_number' => $this->chapter_number,
            'sub_chapter' => $this->sub_chapter,
            'volume_number' => $this->volume_number,
            'title' => $this->title,
            'slug' => $this->slug,
            'formatted_number' => $this->formatted_number,
            'full_title' => $this->full_title,

            // Stats
            'word_count' => $this->word_count,
            'view_count' => $this->view_count ?? 0,

            // Flags
            'is_vip' => $this->is_vip,
            'is_free' => $this->isFree(),

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
