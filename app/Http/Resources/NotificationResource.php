<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Transforms a Laravel DatabaseNotification for API response.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id'         => $this->id,
            'type'       => $data['type'] ?? 'unknown',
            'data'       => $this->formatData($data),
            'read_at'    => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Format notification data based on type.
     *
     * Strips the 'type' key (already at top level) and
     * ensures consistent structure per notification type.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function formatData(array $data): array
    {
        // Remove 'type' — it's already at top level
        unset($data['type']);

        return $data;
    }
}
