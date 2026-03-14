<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'label',
        'description',
        'is_public',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
        'is_public' => 'boolean',
        'updated_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeInGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get a setting value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = "settings.{$key}";

        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            $parts = explode('.', $key, 2);

            if (count($parts) === 2) {
                [$group, $settingKey] = $parts;
            } else {
                $group = 'general';
                $settingKey = $key;
            }

            $setting = self::query()->where('group', $group)
                ->where('key', $settingKey)
                ->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value.
     *
     * Auto-detects the value type to ensure correct casting on read.
     */
    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $parts = explode('.', $key, 2);

        if (count($parts) === 2) {
            [$group, $settingKey] = $parts;
        } else {
            $group = 'general';
            $settingKey = $key;
        }

        self::query()->updateOrCreate(
            ['group' => $group, 'key' => $settingKey],
            [
                'value' => $value,
                'type'  => self::detectType($value),
                'updated_by' => $userId,
                'updated_at' => now(),
            ]
        );

        Cache::forget("settings.{$key}");
    }

    /**
     * Detect the type of a value for storage.
     */
    protected static function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => 'string',
        };
    }

    /**
     * Get all settings in a group.
     */
    public static function getGroup(string $group): array
    {
        return Cache::remember("settings.group.{$group}", 3600, function () use ($group) {
            return self::query()->where('group', $group)
                ->get()
                ->mapWithKeys(function ($setting) {
                    $value = self::castValue($setting->value, $setting->type);

                    return [$setting->key => $value];
                })
                ->toArray();
        });
    }

    /**
     * Get all public settings.
     */
    public static function getPublic(): array
    {
        return Cache::remember('settings.public', 3600, function () {
            return self::query()->where('is_public', true)
                ->get()
                ->mapWithKeys(function ($setting) {
                    $key = "{$setting->group}.{$setting->key}";
                    $value = self::castValue($setting->value, $setting->type);

                    return [$key => $value];
                })
                ->toArray();
        });
    }

    /**
     * Clear all settings cache.
     *
     * Only clears setting-specific keys, NOT the entire cache.
     * Cache::flush() was previously used here but is destructive —
     * it wipes sessions, view counts, permission cache, etc.
     */
    public static function clearCache(): void
    {
        // Forget individual setting keys
        $settings = self::query()->get(['group', 'key']);

        foreach ($settings as $setting) {
            Cache::forget("settings.{$setting->group}.{$setting->key}");
        }

        // Forget group caches
        $groups = $settings->pluck('group')->unique();

        foreach ($groups as $group) {
            Cache::forget("settings.group.{$group}");
        }

        // Forget public settings cache
        Cache::forget('settings.public');
    }

    /**
     * Cast value based on type.
     */
    protected static function castValue(mixed $value, string $type): mixed
    {
        // For array/json types, return as-is (already decoded by Eloquent cast)
        if (in_array($type, ['array', 'json'], true)) {
            return is_array($value) ? $value : json_decode((string) $value, true);
        }

        // Unwrap single-element arrays (Eloquent 'array' cast wraps scalars)
        $raw = is_array($value) ? ($value[0] ?? $value) : $value;

        return match ($type) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            default => is_array($raw) ? json_encode($raw) : (string) $raw,
        };
    }
}
