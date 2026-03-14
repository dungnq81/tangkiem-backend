<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Awcodes\Curator\Models\Media;
use App\Models\ScrapeSource;

class Story extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'author_id',
        'title',
        'slug',
        'alternative_titles',
        'alternative_titles_text',
        'description',
        'content',
        'cover_image_id',
        'thumbnail_id',
        'banner_id',
        'status',
        'origin',
        'is_published',
        'is_featured',
        'is_hot',
        'is_vip',
        'is_locked',
        'published_at',
        'primary_category_id',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'scrape_source_id',
        'scrape_url',
        'scrape_hash',
        'total_chapters',
        'latest_chapter_number',
        'latest_chapter_title',
        'last_chapter_at',
        'total_word_count',
    ];

    protected $casts = [
        'alternative_titles' => 'array',
        'status' => StoryStatus::class,

        'origin' => StoryOrigin::class,
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'is_hot' => 'boolean',
        'is_vip' => 'boolean',
        'is_locked' => 'boolean',
        'published_at' => 'datetime',
        'last_chapter_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function coverImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_image_id');
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_id');
    }

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'banner_id');
    }

    /**
     * All categories for this story (không phân biệt chính/phụ)
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'story_category')
            ->withPivot('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'story_tag')
            ->withPivot('created_at');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class, 'scrape_source_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function bookmarkedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bookmarks')
            ->withTimestamps();
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeHot($query)
    {
        return $query->where('is_hot', true);
    }

    public function scopeByStatus($query, StoryStatus $status)
    {
        return $query->where('status', $status->value);
    }



    public function scopeByOrigin($query, StoryOrigin $origin)
    {
        return $query->where('origin', $origin->value);
    }

    // ═══════════════════════════════════════════════════════════════
    // Accessors & Helpers
    // ═══════════════════════════════════════════════════════════════

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }



    public function getOriginLabelAttribute(): string
    {
        return $this->origin->label();
    }

    public function getOriginFlagAttribute(): string
    {
        return $this->origin->flag();
    }

    public function isCompleted(): bool
    {
        return $this->status === StoryStatus::COMPLETED;
    }

    public function isOngoing(): bool
    {
        return $this->status === StoryStatus::ONGOING;
    }
}
