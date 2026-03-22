<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ApiDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HasSiteScope — Shared trait for models with api_domain_id discriminator.
 *
 * Provides:
 * - apiDomain() relationship
 * - scopeSiteAware() — conditional: filter if site given, else global
 * - scopeForSiteExact() — NULL-safe: matches IS NULL when $siteId = null
 *
 * Used by: PageVisit, DailyAnalytic, Bookmark, Rating, ReadingHistory
 */
trait HasSiteScope
{
    public function apiDomain(): BelongsTo
    {
        return $this->belongsTo(ApiDomain::class);
    }

    /**
     * Conditional site scope: filter if site ID given, otherwise no filter.
     *
     * Usage: $query->siteAware($siteId) — works for both dashboard views.
     */
    public function scopeSiteAware(Builder $query, ?int $apiDomainId): Builder
    {
        if ($apiDomainId !== null) {
            return $query->where('api_domain_id', $apiDomainId);
        }

        return $query; // No filter = all data (global view)
    }

    /**
     * Exact site match — NULL-safe.
     *
     * Unlike siteAware (which returns ALL when null), this scope
     * matches records WHERE api_domain_id IS NULL when $siteId = null.
     *
     * Used by Interaction services for per-site uniqueness lookups.
     */
    public function scopeForSiteExact(Builder $query, ?int $apiDomainId): Builder
    {
        if ($apiDomainId !== null) {
            return $query->where('api_domain_id', $apiDomainId);
        }

        return $query->whereNull('api_domain_id');
    }
}
