<?php

namespace App\Http\Middleware;

use App\Models\Computer;
use App\Services\Agent\AgentSecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentToken
{
    public function __construct(
        private readonly AgentSecurityLogger $security,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    public function handle(Request $request, Closure $next, string $ability = 'agent:report'): Response
    {
        $this->permissionRegistrar->setPermissionsTeamId(null);

        $computer = $request->user();

        if (! $computer instanceof Computer) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $computer->currentAccessToken();

        if ($token === null || $token->cant($ability)) {
            return response()->json(['message' => 'This action is unauthorized.'], Response::HTTP_FORBIDDEN);
        }

        if ($computer->trashed() || $computer->organization_id === null) {
            $this->security->event('agent_computer_without_organization', computer: $computer);

            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $organization = $computer->organization;

        if ($organization === null || $organization->isSuspended()) {
            $this->security->event('agent_inactive_organization', $organization, $computer);

            return response()->json(['message' => 'This action is unauthorized.'], Response::HTTP_FORBIDDEN);
        }

        $tokenOrganizationId = $token->organization_id !== null ? (int) $token->organization_id : null;

        if ($tokenOrganizationId === null) {
            if (! (bool) config('treck.agent.legacy_token_compatibility', true)) {
                $this->security->event('agent_legacy_token_rejected', $organization, $computer);

                return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
            }

            $this->security->event('agent_legacy_token_compatibility_used', $organization, $computer);
        } elseif ($tokenOrganizationId !== (int) $computer->organization_id) {
            $this->security->event('agent_token_organization_mismatch', $organization, $computer, context: [
                'token_organization_id' => $tokenOrganizationId,
                'computer_organization_id' => (int) $computer->organization_id,
            ]);

            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('agent_organization_id', (int) $computer->organization_id);
        $request->attributes->set('agent_organization', $organization);

        return $next($request);
    }
}
