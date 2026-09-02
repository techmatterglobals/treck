<?php

namespace App\Jobs\Middleware;

use App\Services\Tenancy\OrganizationContext;
use Closure;

class SetOrganizationContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $organizationId = property_exists($job, 'organizationId') ? $job->organizationId : null;

        return app(OrganizationContext::class)->run($organizationId, fn () => $next($job));
    }
}
