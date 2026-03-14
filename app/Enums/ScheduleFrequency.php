<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Unified schedule frequency enum — single source of truth for all scheduled tasks.
 *
 * Used by:
 * - ScrapeJob (scrape scheduling)
 * - AiRunScheduled (AI content/SEO auto-generation)
 * - AiSettingsPage (UI dropdowns)
 */
enum ScheduleFrequency: string
{
    case EveryMin = 'every_min';
    case Every2Min = 'every_2_min';
    case Every5Min = 'every_5_min';
    case Every10Min = 'every_10_min';
    case Every30Min = 'every_30_min';
    case Hourly = 'hourly';
    case Every2Hours = 'every_2_hours';
    case Every4Hours = 'every_4_hours';
    case Every6Hours = 'every_6_hours';
    case Every12Hours = 'every_12_hours';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * Human-readable label (Vietnamese).
     */
    public function label(): string
    {
        return match ($this) {
            self::EveryMin => '⏱ Mỗi phút',
            self::Every2Min => '⏱ Mỗi 2 phút',
            self::Every5Min => '⏱ Mỗi 5 phút',
            self::Every10Min => '⏱ Mỗi 10 phút',
            self::Every30Min => '⏱ Mỗi 30 phút',
            self::Hourly => '🕐 Mỗi giờ',
            self::Every2Hours => '🕑 Mỗi 2 giờ',
            self::Every4Hours => '🕓 Mỗi 4 giờ',
            self::Every6Hours => '🕕 Mỗi 6 giờ',
            self::Every12Hours => '🕛 Mỗi 12 giờ',
            self::Daily => '📅 Hàng ngày',
            self::Weekly => '📆 Hàng tuần',
            self::Monthly => '📅 Hàng tháng',
        };
    }

    /**
     * Interval in minutes.
     *
     * For time-based frequencies (daily/weekly/monthly), returns the
     * approximate interval used for cache throttling.
     */
    public function intervalMinutes(): int
    {
        return match ($this) {
            self::EveryMin => 1,
            self::Every2Min => 2,
            self::Every5Min => 5,
            self::Every10Min => 10,
            self::Every30Min => 30,
            self::Hourly => 60,
            self::Every2Hours => 120,
            self::Every4Hours => 240,
            self::Every6Hours => 360,
            self::Every12Hours => 720,
            self::Daily => 1440,
            self::Weekly => 10080,
            self::Monthly => 43200,
        };
    }

    /**
     * All options as [value => label] array for Select dropdowns.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Whether this frequency requires a specific time-of-day setting.
     */
    public function needsTimeConfig(): bool
    {
        return in_array($this, [self::Daily, self::Weekly, self::Monthly]);
    }

    /**
     * Whether this frequency requires a day-of-week config.
     */
    public function needsDayOfWeek(): bool
    {
        return $this === self::Weekly;
    }

    /**
     * Whether this frequency requires a day-of-month config.
     */
    public function needsDayOfMonth(): bool
    {
        return $this === self::Monthly;
    }
}
