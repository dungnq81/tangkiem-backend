<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSiteScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageVisit extends Model
{
    use HasSiteScope;
    /**
     * No auto-managed timestamps — we use visited_at explicitly.
     */
    public $timestamps = false;

    protected $fillable = [
        'visited_at',
        'page_type',
        'page_id',
        'page_slug',
        'session_hash',
        'ip_hash',
        'user_id',
        'referrer_domain',
        'referrer_type',
        'utm_source',
        'utm_medium',
        'device_type',
        'browser',
        'os',
        'country_code',
        'is_bot',
        'api_domain_id',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'page_id'    => 'integer',
        'user_id'    => 'integer',
        'is_bot'     => 'boolean',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeHuman($query)
    {
        return $query->where('is_bot', false);
    }

    public function scopeOfType($query, string $pageType)
    {
        return $query->where('page_type', $pageType);
    }

    public function scopeInDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('visited_at', [$from, $to]);
    }
}
