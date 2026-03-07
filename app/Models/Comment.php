<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'commentable_type',
        'commentable_id',
        'parent_id',
        'depth',
        'content',
        'content_html',
        'is_pinned',
        'is_spoiler',
        'is_hidden',
        'hidden_reason',
        'edited_at',
        'edit_count',
    ];

    protected $casts = [
        'depth' => 'integer',
        'likes_count' => 'integer',
        'replies_count' => 'integer',
        'edit_count' => 'integer',
        'is_pinned' => 'boolean',
        'is_spoiler' => 'boolean',
        'is_hidden' => 'boolean',
        'edited_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForStory($query, int $storyId)
    {
        return $query->where('commentable_type', 'story')
            ->where('commentable_id', $storyId);
    }

    public function scopeForChapter($query, int $chapterId)
    {
        return $query->where('commentable_type', 'chapter')
            ->where('commentable_id', $chapterId);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function isEdited(): bool
    {
        return $this->edit_count > 0;
    }

    public function canReply(): bool
    {
        // Only allow 2 levels of nesting (0, 1)
        return $this->depth < 2;
    }

    public function hide(string $reason): void
    {
        $this->update([
            'is_hidden' => true,
            'hidden_reason' => $reason,
        ]);
    }
}
