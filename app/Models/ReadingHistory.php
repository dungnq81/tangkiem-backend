<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingHistory extends Model
{
    protected $table = 'reading_history';

    protected $fillable = [
        'user_id',
        'story_id',
        'chapter_id',
        'progress',
        'read_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'read_at' => 'datetime',
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

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
