<?php

namespace App\Services\Notifications;

use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Immutable input to the notification rule engine (Phase 9): a source signal
 * (presence change, application-usage row, screenshot, agent event) plus its
 * computer/employee context and a free-form data bag the rules inspect.
 */
class NotificationContext
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $source,
        public readonly array $data = [],
        public readonly ?Computer $computer = null,
        public readonly ?Employee $employee = null,
        public readonly ?Carbon $occurredAt = null,
        public readonly ?int $organizationId = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function when(): Carbon
    {
        return $this->occurredAt ?? now();
    }
}
