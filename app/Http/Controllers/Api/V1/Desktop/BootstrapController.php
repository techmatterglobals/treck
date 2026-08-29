<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Api\V1\Desktop\Concerns\AuthorizesDesktopAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    use AuthorizesDesktopAccess;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('employee.department');
        $this->authorizeDesktopAccess($user);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                'features' => [
                    'presence' => true,
                    'attendance' => $user->can('view attendance'),
                    'reports' => $user->can('view reports'),
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
