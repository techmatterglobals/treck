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
        } catch (CurrentOrganizationException $exception) {
            if ($request->expectsJson()) {
                $status = in_array($exception->reason, ['ambiguous', 'no_membership'], true)
                    ? Response::HTTP_CONFLICT
                    : Response::HTTP_FORBIDDEN;

                return response()->json([
                    'message' => 'No active organization context is available.',
                    'reason' => $exception->reason,
                ], $status);
            }

            if (in_array($exception->reason, ['ambiguous', 'no_membership'], true)) {
                return redirect()->route('organizations.select');
            }

            abort(Response::HTTP_FORBIDDEN, 'No active organization context is available.');
        }

        return $next($request);
    }
}
