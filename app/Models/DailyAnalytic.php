<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSiteScope;
use Illuminate\Database\Eloquent\Model;

class DailyAnalytic extends Model
{
    use HasSiteScope;
    protected $fillable = [
        'date',
        'page_type',
        'page_id',
        'api_domain_id',
        'total_views',
        'unique_visitors',
        'new_visitors',
        'returning_visitors',
        'desktop_views',
        'mobile_views',
        'tablet_views',
        'bot_views',
        'bounce_rate',
        'avg_pages_per_session',
        'referrer_breakdown',
        'browser_breakdown',
        'os_breakdown',
        'country_breakdown',
        'hourly_views',
    ];

    protected $casts = [
        'date'                  => 'date',
        'page_id'               => 'integer',
        'total_views'           => 'integer',
        'unique_visitors'       => 'integer',
        'new_visitors'          => 'integer',
        'returning_visitors'    => 'integer',
        'desktop_views'         => 'integer',
        'mobile_views'          => 'integer',
        'tablet_views'          => 'integer',
        'bot_views'             => 'integer',
        'bounce_rate'           => 'decimal:2',
        'avg_pages_per_session' => 'decimal:2',
        'referrer_breakdown'    => 'array',
        'browser_breakdown'     => 'array',
        'os_breakdown'          => 'array',
        'country_breakdown'     => 'array',
        'hourly_views'          => 'array',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    /**
     * Site-wide aggregates (page_type = null, page_id = null).
     */
    public function scopeSiteWide($query)
    {
        return $query->whereNull('page_type')->whereNull('page_id');
    }

    /**
     * Aggregates by page type (e.g. all stories combined).
     */
    public function scopeByPageType($query, string $pageType)
    {
        return $query->where('page_type', $pageType)->whereNull('page_id');
    }

    /**
     * Specific page (e.g. story_id = 42).
     */
    public function scopeForPage($query, string $pageType, int $pageId)
    {
        return $query->where('page_type', $pageType)->where('page_id', $pageId);
    }

    public function scopeInDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
