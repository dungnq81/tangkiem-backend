<?php

declare(strict_types=1);

namespace App\Services\Analytics\Data;

/**
 * DTO: Overview statistics for a date range.
 *
 * Immutable data object returned by query services.
 * Replaces the raw `array{total_views: int, ...}` pattern.
 */
final readonly class OverviewStats
{
    public function __construct(
        public int $totalViews = 0,
        public int $uniqueVisitors = 0,
        public int $newVisitors = 0,
        public float $bounceRate = 0,
        public float $avgPagesPerSession = 0,
        public int $botViews = 0,
    ) {}

    /**
     * Calculate returning visitors.
     */
    public function returningVisitors(): int
    {
        return max(0, $this->uniqueVisitors - $this->newVisitors);
    }

    /**
     * Check if stats have any data.
     */
    public function isEmpty(): bool
    {
        return $this->totalViews === 0 && $this->uniqueVisitors === 0;
    }

    /**
     * Create from a raw DB aggregate row.
     */
    public static function fromAggregate(object $row): self
    {
        return new self(
            totalViews: (int) ($row->total_views ?? 0),
            uniqueVisitors: (int) ($row->unique_visitors ?? 0),
            newVisitors: (int) ($row->new_visitors ?? 0),
            bounceRate: round((float) ($row->bounce_rate ?? 0), 1),
            avgPagesPerSession: round((float) ($row->avg_pages ?? 0), 1),
            botViews: (int) ($row->bot_views ?? 0),
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'total_views'      => $this->totalViews,
            'unique_visitors'  => $this->uniqueVisitors,
            'new_visitors'     => $this->newVisitors,
            'bounce_rate'      => $this->bounceRate,
            'avg_pages'        => $this->avgPagesPerSession,
            'bot_views'        => $this->botViews,
        ];
    }
}
