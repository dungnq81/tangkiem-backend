<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\ScrapeSource;

class Chapter extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'story_id',
        'chapter_number',
        'sub_chapter',
        'volume_number',
        'title',
        'slug',
        'prev_chapter_id',
        'next_chapter_id',
        'word_count',
        'is_published',
        'is_vip',
        'is_free_preview',
        'published_at',
        'scheduled_at',
        'meta_title',
        'meta_description',
        'scrape_source_id',
        'scrape_url',
        'scrape_hash',
    ];

    protected $casts = [
        'sub_chapter' => 'integer',
        'volume_number' => 'integer',
        'word_count' => 'integer',
        'view_count' => 'integer',
        'comment_count' => 'integer',
        'is_published' => 'boolean',
        'is_vip' => 'boolean',
        'is_free_preview' => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function content(): HasOne
    {
        return $this->hasOne(ChapterContent::class);
    }

    public function prevChapter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prev_chapter_id');
    }

    public function nextChapter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_chapter_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class, 'scrape_source_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // Boot
    // ═══════════════════════════════════════════════════════════════

    protected static function booted(): void
    {
        static::saving(function (Chapter $chapter) {
            if ($chapter->isDirty('chapter_number')) {
                $chapter->chapter_number = self::normalizeChapterNumber($chapter->chapter_number);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeScheduled($query)
    {
        return $query->where('is_published', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now());
    }

    public function scopeOrdered($query)
    {
        return $query
            ->orderBy('sort_key')
            ->orderBy('chapter_number')
            ->orderBy('sub_chapter');
    }

    public function scopeByVolume($query, int $volume)
    {
        return $query->where('volume_number', $volume);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Normalize chapter number: strip leading zeros, trailing .00.
     *
     * "001" → "1", "01" → "1", "1.00" → "1", "1.50" → "1.5"
     * Preserves: "1a", "1b", "1.5", "10", "0"
     */
    public static function normalizeChapterNumber(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0') {
            return $value;
        }

        // Strip leading zeros from numeric prefix: "001" → "1", "01a" → "1a"
        $value = preg_replace('/^0+(\d)/', '$1', $value);

        // Strip trailing decimal zeros: "1.00" → "1", "1.50" → "1.5"
        if (str_contains($value, '.')) {
            // Only for the numeric prefix (before any trailing letter)
            $value = preg_replace_callback(
                '/^(\d+\.\d*?)0*([a-zA-Z]?)$/',
                function ($m) {
                    $num = rtrim($m[1], '.');
                    return $num . $m[2];
                },
                $value
            );
        }

        return $value;
    }

    /**
     * Count words in plain text content.
     *
     * Splits by whitespace — works correctly for Vietnamese (space-separated syllables)
     * and CJK text. Returns 0 for empty/whitespace-only strings.
     */
    public static function countWords(string $text): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text));
    }

    /**
     * Get formatted chapter number (e.g., "Chương 1", "Chương 1.5", "Chương 1a", "Chương 1 - Phần 2").
     */
    public function getFormattedNumberAttribute(): string
    {
        $number = $this->chapter_number;

        if ($this->sub_chapter > 0) {
            return "Chương {$number} - Phần {$this->sub_chapter}";
        }

        return "Chương {$number}";
    }

    /**
     * Get full title (number + title).
     */
    public function getFullTitleAttribute(): string
    {
        $number = $this->formatted_number;

        return $this->title
            ? "{$number}: {$this->title}"
            : $number;
    }

    public function isScheduled(): bool
    {
        return !$this->is_published
            && $this->scheduled_at
            && $this->scheduled_at->isFuture();
    }

    public function isFree(): bool
    {
        return !$this->is_vip || $this->is_free_preview;
    }
}
