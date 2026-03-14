<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Awcodes\Curator\Models\Media;
use App\Models\ScrapeSource;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'image_id',
        'parent_id',
        'depth',
        'path',
        'sort_order',
        'meta_title',
        'meta_description',
        'is_active',
        'is_featured',
        'show_in_menu',
        'scrape_source_id',
        'scrape_url',
        'scrape_hash',
    ];

    protected $casts = [
        'depth' => 'integer',
        'sort_order' => 'integer',
        'stories_count' => 'integer',
        'children_count' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'show_in_menu' => 'boolean',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'story_category')
            ->withPivot('is_primary', 'created_at');
    }

    public function primaryStories(): HasMany
    {
        return $this->hasMany(Story::class, 'primary_category_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
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

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeInMenu($query)
    {
        return $query->where('show_in_menu', true);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function hasChildren(): bool
    {
        return $this->children_count > 0;
    }

    /**
     * Get all ancestors (breadcrumb).
     */
    public function getAncestors(): array
    {
        if (!$this->path) {
            return [];
        }

        $ids = explode('/', trim($this->path, '/'));

        return self::whereIn('id', $ids)
            ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')')
            ->get()
            ->all();
    }
}
