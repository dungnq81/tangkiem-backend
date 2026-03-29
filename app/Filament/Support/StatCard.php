<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * Renders beautiful stat card grids for Filament form statistics sections.
 *
 * Usage:
 *   StatCard::grid([
 *       [ StatCard::item('Label', $value, '📊', 'blue'), ... ],  // row 1
 *       [ StatCard::item('Label', $value, '🔥', 'red'), ... ],   // row 2
 *   ])
 */
class StatCard
{
    /**
     * Create a stat item descriptor array.
     *
     * @return array{label: string, value: mixed, icon: string, color: string}
     */
    public static function item(string $label, mixed $value, string $icon = '📊', string $color = 'blue'): array
    {
        return compact('label', 'value', 'icon', 'color');
    }

    /**
     * Render a grid of stat cards organized in rows.
     *
     * @param  array<int, array<int, array{label: string, value: mixed, icon: string, color: string}>>  $rows
     */
    public static function grid(array $rows): HtmlString
    {
        $html = self::styles();

        foreach ($rows as $index => $row) {
            $cols = count($row);
            $isLast = $index === count($rows) - 1;
            $mbClass = ! $isLast ? ' tk-stats-grid--mb' : '';
            $html .= "<div class=\"tk-stats-grid tk-stats-grid--{$cols}{$mbClass}\">";

            foreach ($row as $stat) {
                $html .= self::renderCard($stat);
            }

            $html .= '</div>';
        }

        return new HtmlString($html);
    }

    /**
     * Render placeholder content when no record exists yet.
     */
    public static function empty(string $message = 'Lưu để xem thống kê'): HtmlString
    {
        return new HtmlString(
            '<div style="padding:1.5rem;text-align:center;color:#9ca3af;font-size:0.875rem;">'
            . e($message)
            . '</div>'
        );
    }

    private static function renderCard(array $stat): string
    {
        $label = e($stat['label'] ?? '');
        $icon = $stat['icon'] ?? '📊';
        $color = e($stat['color'] ?? 'blue');
        $value = $stat['value'] ?? 0;

        // Format: numeric int => number_format, float => 1 decimal, string => as-is, null => dash
        if (is_float($value)) {
            $formatted = number_format($value, 1);
        } elseif (is_numeric($value) && ! is_string($value)) {
            $formatted = number_format((int) $value);
        } else {
            $formatted = (is_string($value) && $value !== '') ? e($value) : '—';
        }

        return <<<HTML
            <div class="tk-stat">
                <div class="tk-stat__icon" data-color="{$color}">
                    <span>{$icon}</span>
                </div>
                <div class="tk-stat__body">
                    <span class="tk-stat__label">{$label}</span>
                    <span class="tk-stat__value">{$formatted}</span>
                </div>
            </div>
        HTML;
    }

    /** Track whether CSS was already injected in this request. */
    private static bool $stylesInjected = false;

    private static function styles(): string
    {
        if (self::$stylesInjected) {
            return '';
        }
        self::$stylesInjected = true;

        return <<<'HTML'
        <style>
        .tk-stats-grid{display:grid;gap:.75rem}
        .tk-stats-grid--mb{margin-bottom:.75rem}
        .tk-stats-grid--1{grid-template-columns:1fr}
        .tk-stats-grid--2{grid-template-columns:repeat(2,1fr)}
        .tk-stats-grid--3{grid-template-columns:repeat(3,1fr)}
        .tk-stats-grid--4{grid-template-columns:repeat(4,1fr)}
        @media(max-width:768px){
            .tk-stats-grid--3,.tk-stats-grid--4{grid-template-columns:repeat(2,1fr)}
        }
        @media(max-width:480px){
            .tk-stats-grid--2,.tk-stats-grid--3,.tk-stats-grid--4{grid-template-columns:1fr}
        }
        .tk-stat{
            display:flex;align-items:center;gap:.875rem;
            padding:1rem 1.125rem;border-radius:.75rem;
            background:#fff;border:1px solid #e5e7eb;
            transition:border-color .15s,box-shadow .15s;
        }
        .dark .tk-stat{background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.08)}
        .tk-stat:hover{border-color:#d1d5db;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .dark .tk-stat:hover{border-color:rgba(255,255,255,.12);box-shadow:0 1px 3px rgba(0,0,0,.2)}
        .tk-stat__icon{
            display:flex;align-items:center;justify-content:center;
            width:2.5rem;height:2.5rem;border-radius:.625rem;
            font-size:1.125rem;line-height:1;flex-shrink:0;
        }
        .tk-stat__icon[data-color="blue"]{background:#eff6ff}
        .tk-stat__icon[data-color="green"]{background:#f0fdf4}
        .tk-stat__icon[data-color="amber"]{background:#fffbeb}
        .tk-stat__icon[data-color="red"]{background:#fef2f2}
        .tk-stat__icon[data-color="cyan"]{background:#ecfeff}
        .tk-stat__icon[data-color="purple"]{background:#faf5ff}
        .tk-stat__icon[data-color="pink"]{background:#fdf2f8}
        .tk-stat__icon[data-color="gray"]{background:#f3f4f6}
        .dark .tk-stat__icon[data-color="blue"]{background:rgba(59,130,246,.12)}
        .dark .tk-stat__icon[data-color="green"]{background:rgba(34,197,94,.12)}
        .dark .tk-stat__icon[data-color="amber"]{background:rgba(245,158,11,.12)}
        .dark .tk-stat__icon[data-color="red"]{background:rgba(239,68,68,.12)}
        .dark .tk-stat__icon[data-color="cyan"]{background:rgba(6,182,212,.12)}
        .dark .tk-stat__icon[data-color="purple"]{background:rgba(147,51,234,.12)}
        .dark .tk-stat__icon[data-color="pink"]{background:rgba(236,72,153,.12)}
        .dark .tk-stat__icon[data-color="gray"]{background:rgba(107,114,128,.1)}
        .tk-stat__body{min-width:0;flex:1}
        .tk-stat__label{
            display:block;font-size:.6875rem;font-weight:600;
            color:#6b7280;text-transform:uppercase;
            letter-spacing:.04em;line-height:1.25;margin-bottom:.1875rem;
        }
        .dark .tk-stat__label{color:#9ca3af}
        .tk-stat__value{
            display:block;font-size:1.25rem;font-weight:700;
            color:#111827;font-variant-numeric:tabular-nums;line-height:1.3;
        }
        .dark .tk-stat__value{color:#f9fafb}
        </style>
        HTML;
    }
}
