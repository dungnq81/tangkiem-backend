<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChapterContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'chapter_id',
        'content',
        'content_html',
        'content_hash',
        'byte_size',
    ];

    protected $casts = [
        'byte_size' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Calculate and set content hash and byte size.
     */
    public function updateMetadata(): void
    {
        $this->content_hash = md5($this->content);
        $this->byte_size = strlen($this->content);
    }

    /**
     * Get formatted byte size.
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->byte_size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
