<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Contracts\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Services\Desktop\DesktopOrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(
        Request $request,
        DesktopOrganizationAccess $access,
        CurrentOrganization $currentOrganization,
    ): JsonResponse {
        $user = $request->user()->loadMissing('employee.department');
        $currentOrganization->clear();
        $organizations = $access->authorizedOrganizations($user);
        $recommended = $organizations->count() === 1 ? $organizations->first() : null;
        $roles = $organizations->pluck('role')->unique()->values();
        $permissions = $organizations
            ->flatMap(fn (array $organization) => $organization['permissions'])
            ->unique()
            ->values();

        return response()->json([
            'data' => [
                'contract_version' => 'desktop-v2',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'roles' => $roles,
                'permissions' => $permissions,
                'organizations' => $organizations,
                'organization_selection_required' => $organizations->count() !== 1,
                'recommended_organization' => $recommended,
                'features' => [
                    'presence' => true,
                    'attendance' => $organizations->contains(fn (array $organization) => $organization['features']['attendance']),
                    'reports' => $organizations->contains(fn (array $organization) => $organization['features']['reports']),
                    'application_usage' => true,
                    'screenshots' => (bool) config('treck.screenshots.enabled'),
                    'downloads' => true,
                    'agent_health' => true,
                ],
                'server' => [
                    'version' => (string) config('treck.version'),
                    'timezone' => (string) config('app.timezone'),
                    'display_timezone' => (string) config('treck.display_timezone'),
                    'time' => now()->utc()->toIso8601String(),
                ],
            ],
        ]);
    }
}
