<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Activity\ActivitySummaryService;
use App\Services\Device\DeviceStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-side activity API for dashboards / clients.
 *
 *   GET /api/v1/activity/live               → live status of all computers
 *   GET /api/v1/activity/{employee}/summary → an employee's daily summary
 */
class ActivityController extends Controller
{
    /** Live online/offline board. Requires the `view attendance` permission. */
    public function live(Request $request, DeviceStatusService $status): JsonResponse
    {
        abort_unless($request->user()->can('view attendance'), Response::HTTP_FORBIDDEN);

        $data = Computer::with('employee.user')->get()->map(fn (Computer $computer) => [
            'computer_id' => $computer->id,
            'hostname' => $computer->hostname,
            'employee' => $computer->employee?->name,
            'status' => $status->resolve($computer)->value,
            'last_seen_at' => optional($computer->last_seen_at)->toIso8601String(),
            'last_activity_at' => optional($computer->last_activity_at)->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    /** Daily active/idle summary for one employee. Admins or the employee. */
    public function summary(Request $request, Employee $employee, ActivitySummaryService $summary): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('view attendance') || $employee->user_id === $user->id,
            Response::HTTP_FORBIDDEN,
        );

        $date = $request->query('date');

        return response()->json(['data' => $summary->dailySummary($employee, $date)]);
    }
}
