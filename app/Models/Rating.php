<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'story_id',
        'rating',
        'review',
        'is_featured',
    ];

    protected $casts = [
        'rating' => 'integer',
        'helpful_count' => 'integer',
        'is_featured' => 'boolean',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeWithReview($query)
    {
        return $query->whereNotNull('review')->where('review', '!=', '');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByRating($query, int $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeHelpful($query, int $minHelpful = 5)
    {
        return $query->where('helpful_count', '>=', $minHelpful);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function hasReview(): bool
    {
        return !empty($this->review);
    }

    /**
     * Get star display (e.g., "★★★★☆").
     */
    public function getStarsAttribute(): string
    {
        $filled = str_repeat('★', $this->rating);
        $empty = str_repeat('☆', 5 - $this->rating);

        return $filled . $empty;
    }
}
