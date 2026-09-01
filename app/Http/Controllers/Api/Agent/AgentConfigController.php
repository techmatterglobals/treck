<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $computer = $request->user();
        $organizationId = $computer->organization_id;

        return response()->json([
            'data' => [
                'computer_id' => $computer->id,
                'revision' => (string) config('treck.agent.policy.revision'),
                'server_time' => now()->utc()->toIso8601String(),
                'policy' => [
                    'organization_id' => $organizationId !== null ? (string) $organizationId : (string) config('treck.agent.policy.organization_id'),
                    'minimum_agent_version' => (string) config('treck.agent.minimum_version'),
                    'health_report_interval_seconds' => (int) config('treck.agent.health_report_interval_seconds'),
                    'presence_offline_timeout_seconds' => (int) config('treck.presence.offline_timeout_seconds'),
                    'activity' => [
                        'heartbeat_interval_seconds' => (int) config('treck.activity.heartbeat_interval_seconds'),
                        'idle_threshold_seconds' => (int) config('treck.activity.idle_threshold_seconds'),
                    ],
                    'screenshots' => [
                        'enabled' => (bool) config('treck.screenshots.enabled'),
                        'interval_seconds' => (int) config('treck.screenshots.interval_seconds'),
                        'blur' => (bool) config('treck.screenshots.blur'),
                        'max_upload_kb' => (int) config('treck.screenshots.max_upload_kb'),
                    ],
                    'downloads' => [
                        'large_file_bytes' => (int) config('treck.downloads.large_file_bytes'),
                        'executable_extensions' => array_values(config('treck.downloads.executable_extensions', [])),
                        'archive_extensions' => array_values(config('treck.downloads.archive_extensions', [])),
                    ],
                ],
            ],
        ]);
    }
}
