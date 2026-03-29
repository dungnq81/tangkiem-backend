<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterContentResource extends JsonResource
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

            // Content
            'content' => $this->whenLoaded('content', fn () => $this->content->content_html ?? $this->content->content),

            // Stats
            'word_count' => $this->word_count,
            'view_count' => $this->view_count ?? 0,

            // Flags
            'is_vip' => $this->is_vip,
            'is_free' => $this->isFree(),

            // Navigation
            'prev_chapter' => $this->when($this->relationLoaded('prevChapter') && $this->prevChapter, fn () => [
                'id' => $this->prevChapter->id,
                'slug' => $this->prevChapter->slug,
                'formatted_number' => $this->prevChapter->formatted_number,
            ]),
            'next_chapter' => $this->when($this->relationLoaded('nextChapter') && $this->nextChapter, fn () => [
                'id' => $this->nextChapter->id,
                'slug' => $this->nextChapter->slug,
                'formatted_number' => $this->nextChapter->formatted_number,
            ]),

            // Story info
            'story' => $this->when($this->relationLoaded('story'), fn () => [
                'id' => $this->story->id,
                'title' => $this->story->title,
                'slug' => $this->story->slug,
            ]),

            // SEO
            'meta' => [
                'title' => $this->meta_title,
                'description' => $this->meta_description,
            ],

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
