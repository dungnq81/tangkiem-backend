<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model with human-readable label support.
 *
 * Extends Spatie's Role to add a `label` column for
 * Vietnamese display names, replacing the hardcoded
 * RoleLabel enum approach.
 *
 * @property string $name      Machine-readable slug (e.g. "super_admin")
 * @property string|null $label Human-readable display name (e.g. "Quản trị viên cấp cao")
 */
class Role extends SpatieRole
{
    /**
     * Get the display label for this role.
     *
     * Falls back to Str::headline(name) if label is empty,
     * which handles roles created before the migration or
     * when label was not provided.
     */
    public function getDisplayLabelAttribute(): string
    {
        return $this->label ?: Str::headline($this->name);
    }

    /**
     * Static helper to get display label from a role name string.
     * Useful in contexts where you only have the name, not the model.
     */
    public static function getLabelByName(string $roleName): string
    {
        $role = static::findByName($roleName);

        return $role->display_label;
    }

    /**
     * Static helper to get display label with graceful fallback.
     * Won't throw if role doesn't exist.
     */
    public static function getDisplayLabel(string $roleName): string
    {
        $role = static::query()->where('name', $roleName)->first();

        return $role?->display_label ?? Str::headline($roleName);
    }
}
