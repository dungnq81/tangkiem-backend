<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlugRedirect extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'model_type',
        'model_id',
        'old_slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeForModel($query, string $type, int $id)
    {
        return $query->where('model_type', $type)->where('model_id', $id);
    }

    public function scopeForStory($query, int $storyId)
    {
        return $query->forModel('story', $storyId);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->forModel('category', $categoryId);
    }

    public function scopeForAuthor($query, int $authorId)
    {
        return $query->forModel('author', $authorId);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Find the current slug by old slug.
     */
    public static function findCurrentSlug(string $modelType, string $oldSlug): ?string
    {
        $redirect = self::query()->where('model_type', $modelType)
            ->where('old_slug', $oldSlug)
            ->first();

        if (!$redirect) {
            return null;
        }

        // Get the model class
        $modelClass = match ($modelType) {
            'story' => Story::class,
            'category' => Category::class,
            'author' => Author::class,
            'tag' => Tag::class,
            default => null,
        };

        if (!$modelClass) {
            return null;
        }

        return $modelClass::query()->find($redirect->model_id)?->slug;
    }

    /**
     * Create redirect for slug change.
     */
    public static function createForSlugChange(
        string $modelType,
        int $modelId,
        string $oldSlug
    ): self {
        return self::query()->create([
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_slug' => $oldSlug,
        ]);
    }
}
