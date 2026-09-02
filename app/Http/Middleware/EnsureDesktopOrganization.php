<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Services\Desktop\DesktopOrganizationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDesktopOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly DesktopOrganizationAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentOrganization->clear();

        $user = $request->user();
        if (! $user instanceof User) {
            return $this->jsonError('Unauthenticated.', 'unauthenticated', Response::HTTP_UNAUTHORIZED);
        }

        $organizationId = $request->headers->get(DesktopOrganizationAccess::HEADER);
        if ($organizationId === null || trim($organizationId) === '' || filter_var($organizationId, FILTER_VALIDATE_INT) === false) {
            return $this->jsonError('Select an organization before using Treck Admin.', 'organization_required', Response::HTTP_CONFLICT);
        }

        $organization = Organization::query()->find((int) $organizationId);
        if (! $organization instanceof Organization) {
            return $this->jsonError('This organization is not available.', 'organization_forbidden', Response::HTTP_FORBIDDEN);
        }

        if (! $organization->isActive() || $organization->isSuspended()) {
            return $this->jsonError('This organization is inactive.', 'organization_inactive', Response::HTTP_CONFLICT);
        }

        if (! $user->activeMembershipFor($organization)) {
            return $this->jsonError('This organization is not available.', 'organization_forbidden', Response::HTTP_FORBIDDEN);
        }

        $membership = $this->currentOrganization->membership($user, $organization->id);
        $role = $this->access->effectiveRole($user, $organization);
        if ($role === null) {
            $this->currentOrganization->clear();

            return $this->jsonError('This organization is not available.', 'organization_forbidden', Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('desktop_organization', $organization);
        $request->attributes->set('desktop_organization_id', $organization->id);
        $request->attributes->set('desktop_organization_role', $role);
        $request->attributes->set('desktop_organization_membership', $membership);
        $request->attributes->set('desktop_capabilities', $this->access->capabilities($role));

        try {
            return $next($request);
        } finally {
            $this->currentOrganization->clear();
        }
    }

    private function jsonError(string $message, string $code, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
