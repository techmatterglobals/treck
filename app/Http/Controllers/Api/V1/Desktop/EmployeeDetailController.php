<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Api\V1\Desktop\Concerns\AuthorizesDesktopAccess;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Activity\ActivitySummaryService;
use App\Services\Hierarchy\EmployeeVisibility;
use App\Services\Presence\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeDetailController extends Controller
{
    use AuthorizesDesktopAccess;

    public function __invoke(
        Request $request,
        Employee $employee,
        EmployeeVisibility $visibility,
        ActivitySummaryService $activity,
        PresenceService $presence,
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeDesktopAccess($user);
        abort_unless($visibility->canSeeEmployee($user, $employee->id), Response::HTTP_FORBIDDEN);

        $employee->loadMissing(['user', 'department', 'manager']);
        $computerIds = $employee->computers()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $computers = $presence->rows($computerIds)->map(fn (array $row) => [
            'computer_id' => $row['computer_id'],
            'computer_name' => $row['computer_name'],
            'status' => $row['status']->value,
            'last_heartbeat_at' => $row['last_heartbeat_at']?->toIso8601String(),
            'last_activity_at' => $row['last_activity_at']?->toIso8601String(),
            'active_seconds' => $row['active_seconds'],
            'idle_seconds' => $row['idle_seconds'],
        ])->values();

        return response()->json(['data' => [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation,
                'department' => $employee->department?->name,
                'manager' => $employee->manager?->name,
            ],
            'today' => $activity->dailySummary($employee),
            'computers' => $computers,
            'generated_at' => now()->utc()->toIso8601String(),
        ]]);
    }
}
