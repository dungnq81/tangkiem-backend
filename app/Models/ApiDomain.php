<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ApiDomain extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'public_key',
        'secret_key',
        'allowed_groups',
        'valid_from',
        'valid_until',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'allowed_groups' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'allowed_groups' => '[]',
    ];

    protected $hidden = [
        'secret_key',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Boot
    // ═══════════════════════════════════════════════════════════════

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->public_key)) {
                $model->public_key = self::generateKey();
            }
            if (empty($model->secret_key)) {
                $model->secret_key = self::generateKey();
            }
        });

        // Clear cached lookups when domain settings change
        static::saved(function (self $model): void {
            $model->clearCache();
        });

        static::deleted(function (self $model): void {
            $model->clearCache();
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        return true;
    }

    public function canAccessGroup(string $group): bool
    {
        $allowed = $this->allowed_groups ?? [];

        return in_array('*', $allowed) || in_array($group, $allowed);
    }

    public static function generateKey(): string
    {
        return Str::random(64);
    }

    /**
     * Clear cached lookups for this domain.
     */
    public function clearCache(): void
    {
        Cache::forget('api_domain:pub:' . hash('xxh3', $this->public_key));
        Cache::forget('api_domain:sec:' . hash('xxh3', $this->secret_key));
        Cache::forget('cors:allowed_origins');
    }

    public function regenerateKeys(): self
    {
        // Clear old key caches
        $this->clearCache();

        $this->update([
            'public_key' => self::generateKey(),
            'secret_key' => self::generateKey(),
        ]);

        return $this;
    }
}
