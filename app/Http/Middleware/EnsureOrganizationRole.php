<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\OrganizationAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationRole
{
    public function __construct(private readonly OrganizationAuthorization $authorization) {}

    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        abort_unless($user && $this->authorization->hasOrganizationRole($user, explode('|', $roles)), 403);

        return $next($request);
    }
}
