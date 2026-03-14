<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'description',
        'color',
        'is_active',
    ];

    protected $casts = [
        'type' => TagType::class,
        'stories_count' => 'integer',
        'is_active' => 'boolean',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'story_tag')
            ->withPivot('created_at');
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, TagType $type)
    {
        return $query->where('type', $type->value);
    }

    public function scopeTags($query)
    {
        return $query->where('type', TagType::TAG->value);
    }

    public function scopeWarnings($query)
    {
        return $query->where('type', TagType::WARNING->value);
    }

    public function scopeAttributes($query)
    {
        return $query->where('type', TagType::ATTRIBUTE->value);
    }

    public function scopePopular($query, int $limit = 20)
    {
        return $query->orderByDesc('stories_count')->limit($limit);
    }
}
