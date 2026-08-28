<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEventType;
use App\Models\NotificationRule;
use Illuminate\Support\Collection;

/**
 * A snapshot of the configured {@see NotificationRule} rows keyed by event type
 * (Phase 9). Passed to rules so they can read thresholds/config and skip work
 * for disabled types, and used by the engine to resolve severity/channels/throttle.
 */
class RuleSet
{
    /** @param Collection<string,NotificationRule> $rules */
    public function __construct(private readonly Collection $rules) {}

    public function rule(NotificationEventType $type): ?NotificationRule
    {
        return $this->rules->get($type->value);
    }

    public function enabled(NotificationEventType $type): bool
    {
        return (bool) ($this->rule($type)?->enabled);
    }

    public function config(NotificationEventType $type, string $key, mixed $default = null): mixed
    {
        return $this->rule($type)?->setting($key, $default) ?? $default;
    }
}
