<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Awcodes\Curator\Models\Media;
use App\Models\ScrapeSource;

class Author extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'original_name',
        'avatar_id',
        'bio',
        'description',
        'social_links',
        'meta_title',
        'meta_description',
        'is_active',
        'is_verified',
        'scrape_source_id',
        'scrape_url',
        'scrape_hash',
    ];

    protected $casts = [
        'social_links' => 'array',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'stories_count' => 'integer',
        'total_views' => 'integer',
        'total_chapters' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_id');
    }

    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class, 'scrape_source_id');
    }



    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function getDisplayNameAttribute(): string
    {
        return $this->original_name
            ? "{$this->name} ({$this->original_name})"
            : $this->name;
    }
}
