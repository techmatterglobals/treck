<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEventType;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\NotificationRule;

/**
 * A candidate notification produced by a rule (Phase 9). It describes WHAT
 * happened; the engine decides whether/how to deliver it (severity, channels,
 * throttling, recipients) from the matching {@see NotificationRule}.
 *
 * `dedupeKey` identifies "the same alert" for flood-prevention/throttling.
 */
class NotificationDraft
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly NotificationEventType $type,
        public readonly string $title,
        public readonly string $message,
        public readonly string $dedupeKey,
        public readonly ?Computer $computer = null,
        public readonly ?Employee $employee = null,
        public readonly array $metadata = [],
    ) {}
}
