<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetOrganizationContext;
use App\Services\Productivity\ProductivityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued daily productivity rollup for a given date (defaults to today).
 */
class GenerateDailyProductivity implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $date = null,
        public readonly ?int $organizationId = null,
    ) {}

    public function middleware(): array
    {
        return [new SetOrganizationContext];
    }

    public function handle(ProductivityService $service): void
    {
        $service->generateDaily($this->date, $this->organizationId);
    }
}
