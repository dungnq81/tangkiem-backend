<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeItem extends Model
{
    protected $table = 'scrape_items';

    protected $fillable = [
        'job_id',
        'raw_data',
        'source_url',
        'source_hash',
        'status',
        'error_message',
        'page_number',
        'sort_order',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'page_number' => 'integer',
        'sort_order' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Constants
    // ═══════════════════════════════════════════════════════════════

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SELECTED = 'selected';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_MERGED = 'merged';

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function job(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class, 'job_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate source_hash from URL.
     */
    public static function hashUrl(string $url): string
    {
        return hash('sha256', trim($url));
    }

    public function getTitle(): string
    {
        $data = $this->raw_data;

        return $data['title'] ?? $data['name'] ?? $this->source_url;
    }
}
