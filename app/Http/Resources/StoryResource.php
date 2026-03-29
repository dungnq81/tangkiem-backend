<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'alternative_titles' => $this->alternative_titles,
            'description' => $this->description,
            'content' => $this->when($request->routeIs('*.show'), $this->content),

            // Status & Origin
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'origin' => $this->origin?->value,
            'origin_label' => $this->origin_label,
            'origin_flag' => $this->origin_flag,

            // Flags
            'is_featured' => $this->is_featured,
            'is_hot' => $this->is_hot,
            'is_vip' => $this->is_vip,

            // Stats
            'view_count' => $this->view_count ?? 0,
            'chapter_count' => $this->chapter_count ?? 0,
            'rating_avg' => round((float) ($this->rating ?? 0), 2),
            'rating_count' => (int) ($this->rating_count ?? 0),

            // Relations
            'author' => new AuthorResource($this->whenLoaded('author')),
            'primary_category' => new CategoryResource($this->whenLoaded('primaryCategory')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'chapters' => ChapterResource::collection($this->whenLoaded('chapters')),

            // Images
            'cover_image' => $this->whenLoaded('coverImage', fn () => [
                'url' => $this->coverImage?->url,
                'alt' => $this->coverImage?->alt,
            ]),

            // SEO
            'meta' => $this->when($request->routeIs('*.show'), [
                'title' => $this->meta_title,
                'description' => $this->meta_description,
                'keywords' => $this->meta_keywords,
                'canonical_url' => $this->canonical_url,
            ]),

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'last_chapter_at' => $this->last_chapter_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
