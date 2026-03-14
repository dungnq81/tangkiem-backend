<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduleFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class ScrapeJob extends Model
{
    protected $table = 'scrape_jobs';

    protected static function booted(): void
    {
        // Auto-cleanup: when user changes target_url or parent_story_id,
        // the old items are from a different story/source and must be purged.
        // Without this, old items pollute the list, stats, and import targets.
        static::updating(function (self $job) {
            $dirty = $job->getDirty();

            $targetChanged = isset($dirty['target_url']) && $job->getOriginal('target_url') !== null;
            $storyChanged = isset($dirty['parent_story_id']) && $job->getOriginal('parent_story_id') !== null;

            if (! $targetChanged && ! $storyChanged) {
                return;
            }

            $deletedCount = $job->items()->delete();

            if ($deletedCount > 0) {
                // Reset job to fresh state
                $job->status = self::STATUS_DRAFT;
                $job->error_log = null;
                $job->detail_status = null;
                $job->detail_fetched = 0;
                $job->detail_total = 0;
                $job->current_page = 0;
                $job->total_pages = 0;

                Log::info('Scrape items purged due to job config change', [
                    'job_id'        => $job->id,
                    'deleted_items' => $deletedCount,
                    'target_changed' => $targetChanged,
                    'story_changed'  => $storyChanged,
                    'old_url'       => $targetChanged ? $job->getOriginal('target_url') : null,
                    'new_url'       => $targetChanged ? $dirty['target_url'] : null,
                    'old_story'     => $storyChanged ? $job->getOriginal('parent_story_id') : null,
                    'new_story'     => $storyChanged ? $dirty['parent_story_id'] : null,
                ]);
            }
        });
    }

    protected $fillable = [
        'source_id',
        'entity_type',
        'name',
        'target_url',
        'selectors',
        'ai_prompt',
        'pagination',
        'detail_config',
        'import_defaults',
        'parent_story_id',
        'status',
        'detail_status',
        'total_pages',
        'current_page',
        'detail_fetched',
        'detail_total',
        'error_log',
        'is_scheduled',
        'auto_import',
        'schedule_frequency',
        'schedule_time',
        'schedule_day_of_week',
        'schedule_day_of_month',
        'last_scheduled_at',
        'created_by',
    ];

    protected $casts = [
        'selectors' => 'array',
        'pagination' => 'array',
        'detail_config' => 'array',
        'import_defaults' => 'array',
        'total_pages' => 'integer',
        'current_page' => 'integer',
        'detail_fetched' => 'integer',
        'detail_total' => 'integer',
        'is_scheduled' => 'boolean',
        'auto_import' => 'boolean',
        'schedule_day_of_week' => 'integer',
        'schedule_day_of_month' => 'integer',
        'last_scheduled_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Constants
    // ═══════════════════════════════════════════════════════════════

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCRAPING = 'scraping';
    public const STATUS_SCRAPED = 'scraped';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_AUTHOR = 'author';
    public const ENTITY_STORY = 'story';
    public const ENTITY_CHAPTER = 'chapter';
    public const ENTITY_CHAPTER_DETAIL = 'chapter_detail';

    public const DETAIL_STATUS_FETCHING = 'fetching';
    public const DETAIL_STATUS_FETCHED = 'fetched';
    public const DETAIL_STATUS_FAILED = 'failed';

    public const ENTITY_TYPES = [
        self::ENTITY_CATEGORY,
        self::ENTITY_AUTHOR,
        self::ENTITY_STORY,
        self::ENTITY_CHAPTER,
        self::ENTITY_CHAPTER_DETAIL,
    ];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCRAPING,
        self::STATUS_SCRAPED,
        self::STATUS_IMPORTING,
        self::STATUS_DONE,
        self::STATUS_FAILED,
    ];

    /**
     * Available schedule frequencies — delegates to ScheduleFrequency enum.
     *
     * @deprecated Use ScheduleFrequency::options() directly.
     */
    public const SCHEDULE_FREQUENCIES = 'use_enum';

    /**
     * Get schedule frequency options for UI dropdowns.
     *
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        return ScheduleFrequency::options();
    }

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class, 'source_id');
    }

    public function parentStory(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'parent_story_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScrapeItem::class, 'job_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeByEntityType($query, string $type)
    {
        return $query->where('entity_type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function markScraping(): void
    {
        $this->update(['status' => self::STATUS_SCRAPING]);
    }

    public function markScraped(): void
    {
        $this->update(['status' => self::STATUS_SCRAPED]);
    }

    public function markDone(): void
    {
        $this->update(['status' => self::STATUS_DONE]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_log' => $error,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Chapter Detail Helpers
    // ═══════════════════════════════════════════════════════════════

    public function isChapterType(): bool
    {
        return $this->entity_type === self::ENTITY_CHAPTER;
    }

    public function isChapterDetailType(): bool
    {
        return $this->entity_type === self::ENTITY_CHAPTER_DETAIL;
    }

    public function hasDetailConfig(): bool
    {
        $config = $this->detail_config;

        if (! $this->isChapterType() || ! is_array($config) || empty($config)) {
            return false;
        }

        // Has explicit extraction config (CSS or AI)
        if (! empty($config['content_selector'] ?? null) || ! empty($config['ai_prompt'] ?? null)) {
            return true;
        }

        // Has auto_fetch_content enabled (AI source will use its own extraction)
        if (($config['auto_fetch_content'] ?? false) === true) {
            return true;
        }

        return false;
    }

    public function needsDetailFetch(): bool
    {
        return $this->hasDetailConfig()
            && $this->status === self::STATUS_SCRAPED
            && $this->detail_status !== self::DETAIL_STATUS_FETCHED;
    }

    public function markDetailFetching(int $total): void
    {
        $this->update([
            'detail_status'  => self::DETAIL_STATUS_FETCHING,
            'detail_total'   => $total,
            'detail_fetched' => 0,
        ]);
    }

    public function markDetailFetched(): void
    {
        $this->update(['detail_status' => self::DETAIL_STATUS_FETCHED]);
    }

    public function markDetailFailed(string $error): void
    {
        $this->update([
            'detail_status' => self::DETAIL_STATUS_FAILED,
            'error_log'     => $error,
        ]);
    }

    public function incrementDetailFetched(): void
    {
        $this->increment('detail_fetched');
    }

    /**
     * Resolve AI prompt: job-level override → source template fallback.
     */
    public function resolveAiPrompt(): ?string
    {
        return $this->ai_prompt ?? $this->source->ai_prompt_template;
    }

    // ═══════════════════════════════════════════════════════════════
    // Schedule Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Whether job is not currently running.
     */
    public function isRunnable(): bool
    {
        return ! in_array($this->status, [self::STATUS_SCRAPING, self::STATUS_IMPORTING]);
    }

    /**
     * Get interval minutes for interval-based frequencies.
     */
    public function getScheduleIntervalMinutes(): ?int
    {
        $freq = ScheduleFrequency::tryFrom($this->schedule_frequency);
        if (! $freq || $freq->needsTimeConfig()) {
            return null; // time-based: daily/weekly/monthly are not interval-based
        }

        return $freq->intervalMinutes();
    }

    /**
     * Determine if this job is due for its next scheduled run.
     */
    public function isDueForScheduledRun(): bool
    {
        if (! $this->is_scheduled || ! $this->schedule_frequency) {
            return false;
        }

        // Interval-based frequencies
        $intervalMinutes = $this->getScheduleIntervalMinutes();
        if ($intervalMinutes !== null) {
            if (! $this->last_scheduled_at) {
                return true;
            }

            return $this->last_scheduled_at->diffInMinutes(now()) >= $intervalMinutes;
        }

        // Time-based: daily
        if ($this->schedule_frequency === 'daily') {
            $targetTime = $this->schedule_time ?? '00:00';
            $now = now();

            if ($now->format('H:i') >= $targetTime) {
                return ! $this->last_scheduled_at || ! $this->last_scheduled_at->isToday();
            }

            return false;
        }

        // Time-based: weekly
        if ($this->schedule_frequency === 'weekly') {
            $targetDay = $this->schedule_day_of_week ?? 1;
            $targetTime = $this->schedule_time ?? '00:00';
            $now = now();

            if ($now->dayOfWeek === $targetDay && $now->format('H:i') >= $targetTime) {
                return ! $this->last_scheduled_at
                    || $this->last_scheduled_at->startOfWeek()->lt($now->startOfWeek());
            }

            return false;
        }

        // Time-based: monthly
        if ($this->schedule_frequency === 'monthly') {
            $targetDay = $this->schedule_day_of_month ?? 1;
            $targetTime = $this->schedule_time ?? '00:00';
            $now = now();

            if ($now->day === $targetDay && $now->format('H:i') >= $targetTime) {
                return ! $this->last_scheduled_at
                    || $this->last_scheduled_at->format('Y-m') !== $now->format('Y-m');
            }

            return false;
        }

        return false;
    }

    /**
     * Human-readable schedule frequency label.
     */
    public function getScheduleFrequencyLabelAttribute(): ?string
    {
        if (! $this->schedule_frequency) {
            return null;
        }

        $freq = ScheduleFrequency::tryFrom($this->schedule_frequency);
        $label = $freq?->label() ?? $this->schedule_frequency;

        if ($this->schedule_frequency === 'daily' && $this->schedule_time) {
            $label .= " lúc {$this->schedule_time}";
        }

        if ($this->schedule_frequency === 'weekly' && $this->schedule_time) {
            $dayNames = [0 => 'CN', 1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7'];
            $day = $dayNames[$this->schedule_day_of_week ?? 1] ?? '?';
            $label .= " ({$day} lúc {$this->schedule_time})";
        }

        if ($this->schedule_frequency === 'monthly' && $this->schedule_time) {
            $day = $this->schedule_day_of_month ?? 1;
            $label .= " (Ngày {$day} lúc {$this->schedule_time})";
        }

        return $label;
    }
}
