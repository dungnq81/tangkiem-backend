<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url ?? $this->whenLoaded('avatar', fn () => $this->avatar?->url),
            'is_vip' => $this->is_vip,
            'is_author' => $this->is_author,

            // Stats
            'bookmark_count' => $this->whenCounted('bookmarks'),
            'history_count' => $this->whenCounted('readingHistory'),

            // Timestamps
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
