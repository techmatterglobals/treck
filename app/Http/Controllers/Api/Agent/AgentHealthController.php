<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAgentHealthRequest;
use App\Models\AgentHealthReport;
use App\Services\Tenancy\MonitoringTenantOwnership;
use Illuminate\Http\JsonResponse;

class AgentHealthController extends Controller
{
    public function __invoke(StoreAgentHealthRequest $request, MonitoringTenantOwnership $ownership): JsonResponse
    {
        $computer = $request->user();
        $data = $request->validated();
        $receivedAt = now();

        $report = AgentHealthReport::updateOrCreate(
            ['computer_id' => $computer->id],
            [
                'organization_id' => $ownership->forComputer($computer),
                'agent_version' => $data['agent_version'],
                'config_revision' => $data['config_revision'],
                'pending_event_count' => $data['pending_event_count'],
                'helper_running' => $data['helper_running'],
                'helper_session_id' => $data['helper_session_id'] ?? null,
                'service_started_at' => $data['service_started_at'] ?? null,
                'last_capture_at' => $data['last_capture_at'] ?? null,
                'last_successful_sync_at' => $data['last_successful_sync_at'] ?? null,
                'last_error_category' => $data['last_error_category'] ?? null,
                'reported_at' => $data['report_time'],
                'received_at' => $receivedAt,
            ],
        );

        if ($computer->agent_version !== $data['agent_version']) {
            $computer->forceFill(['agent_version' => $data['agent_version']])->save();
        }

        return response()->json([
            'data' => [
                'id' => $report->id,
                'computer_id' => $computer->id,
                'received_at' => $receivedAt->utc()->toIso8601String(),
            ],
        ]);
    }
}
