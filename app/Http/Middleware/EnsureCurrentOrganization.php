<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentOrganization;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $organization = $this->currentOrganization->resolve();
            $request->attributes->set('current_organization', $organization);
        } catch (CurrentOrganizationException) {
            abort(Response::HTTP_FORBIDDEN, 'No active organization context is available.');
        }

        return $next($request);
    }
}
