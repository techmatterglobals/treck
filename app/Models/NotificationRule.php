<?php

namespace App\Models;

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable notification rule (Phase 9), keyed by event type. Holds the
 * severity, enabled channels, per-rule config (thresholds / lists) and the
 * throttle window used to prevent flooding. Editable from the settings UI.
 */
class NotificationRule extends Model
{
    protected $fillable = [
        'event_type', 'enabled', 'severity', 'channels', 'config', 'throttle_seconds',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'channels' => 'array',
            'config' => 'array',
            'throttle_seconds' => 'integer',
        ];
    }

    public function type(): ?NotificationEventType
    {
        return NotificationEventType::tryFromValue($this->event_type);
    }

    protected function severityEnum(): Attribute
    {
        return Attribute::make(
            get: fn () => NotificationSeverity::tryFrom($this->severity) ?? NotificationSeverity::Info,
        );
    }

    /** Typed config accessor with a default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
