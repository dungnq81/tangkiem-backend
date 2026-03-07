<?php

declare(strict_types=1);

namespace App\Models;

use Awcodes\Curator\Models\Media;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use HasPanelShield;
    use HasRoles;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar_id',
        'avatar_url',
        'password',
        'is_active',
        'is_vip',
        'is_author',
        'last_active_at',
        'is_banned',
        'banned_until',
        'ban_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_vip' => 'boolean',
            'is_author' => 'boolean',
            'is_banned' => 'boolean',
            'last_active_at' => 'datetime',
            'banned_until' => 'datetime',
        ];
    }

    /**
     * Determine if the user can access the Filament admin panel.
     *
     * Priority: HasPanelShield (role-based) > email domain fallback.
     * This allows super_admin and users with proper roles to access,
     * while also permitting @tangkiem.com emails for development.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Banned users cannot access admin panel
        if ($this->isBanned()) {
            return false;
        }

        // Check role-based access via HasPanelShield
        if ($this->hasRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        // Fallback: allow all @tangkiem.com emails (for development)
        return str_ends_with($this->email, '@tangkiem.com');
    }

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function bookmarkedStories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'bookmarks')
            ->withTimestamps();
    }

    public function readingHistory(): HasMany
    {
        return $this->hasMany(ReadingHistory::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ban System Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check if user is currently banned (permanent or temporary).
     */
    public function isBanned(): bool
    {
        // Permanent ban
        if ($this->is_banned) {
            return true;
        }

        // Temporary ban (still active)
        if ($this->banned_until && $this->banned_until->isFuture()) {
            return true;
        }

        return false;
    }

    /**
     * Check if user has a temporary ban.
     */
    public function hasTemporaryBan(): bool
    {
        return !$this->is_banned
            && $this->banned_until
            && $this->banned_until->isFuture();
    }

    /**
     * Get formatted ban message for display.
     */
    public function getBanMessage(): string
    {
        $message = 'Tài khoản của bạn đã bị khóa.';

        if ($this->ban_reason) {
            $message .= ' Lý do: ' . $this->ban_reason;
        }

        if ($this->hasTemporaryBan()) {
            $message .= ' Mở khóa vào: ' . $this->banned_until->format('d/m/Y H:i');
        }

        return $message;
    }
}
