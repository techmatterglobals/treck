<?php

namespace App\Jobs;

use App\Models\Computer;
use App\Models\Employee;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Evaluates notification rules off the request/ingest path (Phase 9). Observers
 * dispatch this with scalar data + ids so rule evaluation never blocks agent
 * sync, presence, application tracking or screenshot uploads. The engine then
 * queues the actual delivery separately.
 */
class EvaluateNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $source,
        public readonly ?int $computerId = null,
        public readonly ?int $employeeId = null,
        public readonly array $data = [],
    ) {}

    public function handle(NotificationEngine $engine): void
    {
        $computer = $this->computerId ? Computer::find($this->computerId) : null;
        $employee = $this->employeeId
            ? Employee::find($this->employeeId)
            : $computer?->employee;

        $engine->dispatch(new NotificationContext(
            source: $this->source,
            data: $this->data,
            computer: $computer,
            employee: $employee,
        ));
    }
}
