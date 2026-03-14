<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'log_name',
        'description',
        'causer_type',
        'causer_id',
        'subject_type',
        'subject_id',
        'event',
        'properties',
        'batch_uuid',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships (Polymorphic)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the causer (user who performed the action).
     */
    public function causer()
    {
        if (!$this->causer_type || !$this->causer_id) {
            return null;
        }

        return $this->causer_type::find($this->causer_id);
    }

    /**
     * Get the subject (model that was affected).
     */
    public function subject()
    {
        if (!$this->subject_type || !$this->subject_id) {
            return null;
        }

        return $this->subject_type::find($this->subject_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeInLog($query, string $logName)
    {
        return $query->where('log_name', $logName);
    }

    public function scopeByEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeByCauser($query, Model $causer)
    {
        return $query->where('causer_type', $causer::class)
            ->where('causer_id', $causer->getKey());
    }

    public function scopeBySubject($query, Model $subject)
    {
        return $query->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }

    public function scopeInBatch($query, string $batchUuid)
    {
        return $query->where('batch_uuid', $batchUuid);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get old values from properties.
     */
    public function getOldAttribute(): array
    {
        return $this->properties['old'] ?? [];
    }

    /**
     * Get new values from properties.
     */
    public function getAttributesChangedAttribute(): array
    {
        return $this->properties['attributes'] ?? [];
    }

    /**
     * Get changed fields.
     */
    public function getChangedFieldsAttribute(): array
    {
        return array_keys($this->attributes_changed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static Logging Methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Log an activity.
     */
    public static function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        string $event = 'default',
        array $properties = [],
        string $logName = 'default'
    ): self {
        return self::query()->create([
            'log_name' => $logName,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'event' => $event,
            'properties' => $properties,
        ]);
    }
}
