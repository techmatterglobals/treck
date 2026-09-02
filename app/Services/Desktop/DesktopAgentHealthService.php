<?php

namespace App\Services\Desktop;

use App\Models\Computer;
use App\Models\User;
use App\Services\Tenancy\MonitoringTenantAccess;

class DesktopAgentHealthService
{
    public function __construct(private readonly MonitoringTenantAccess $tenant) {}

    /** @return array<string,mixed> */
    public function forUser(User $user, int $organizationId): array
    {
        $computerIds = $this->tenant->visibleComputerIds($user);
        $staleAfter = now()->subSeconds((int) config('treck.agent.health_stale_seconds'));
        $expectedVersion = (string) config('treck.agent.minimum_version');

        $query = Computer::query()
            ->where('organization_id', $organizationId)
            ->with(['employee.department', 'agentHealthReport'])
            ->orderBy('hostname');

        if ($computerIds !== null) {
            $query->whereIn('id', $computerIds ?: [0]);
        }

        $items = $query->get()->map(function (Computer $computer) use ($staleAfter, $expectedVersion) {
            $report = $computer->agentHealthReport;
            $status = 'never_reported';

            if ($report) {
                $status = $report->received_at->lt($staleAfter) ? 'stale' : 'healthy';
            }

            $agentVersion = $report?->agent_version ?? $computer->agent_version;
            $versionCompliance = match (true) {
                $agentVersion === null || $agentVersion === '' => 'unknown',
                version_compare($agentVersion, $expectedVersion, '>=') => 'compliant',
                default => 'outdated',
            };

            return [
                'computer_id' => $computer->id,
                'employee_id' => $computer->employee_id,
                'computer_name' => $computer->hostname ?? $computer->device_uuid,
                'employee' => $computer->employee?->name,
                'department' => $computer->employee?->department?->name,
                'status' => $status,
                'agent_version' => $agentVersion,
                'expected_version' => $expectedVersion,
                'version_compliance' => $versionCompliance,
                'config_revision' => $report?->config_revision,
                'pending_event_count' => $report?->pending_event_count,
                'helper_running' => $report?->helper_running,
                'helper_session_id' => $report?->helper_session_id,
                'service_started_at' => $report?->service_started_at?->toIso8601String(),
                'last_capture_at' => $report?->last_capture_at?->toIso8601String(),
                'last_successful_sync_at' => $report?->last_successful_sync_at?->toIso8601String(),
                'last_error_category' => $report?->last_error_category,
                'reported_at' => $report?->reported_at?->toIso8601String(),
                'received_at' => $report?->received_at?->toIso8601String(),
            ];
        })->values();

        return [
            'items' => $items,
            'summary' => [
                'total' => $items->count(),
                'healthy' => $items->where('status', 'healthy')->count(),
                'stale' => $items->where('status', 'stale')->count(),
                'never_reported' => $items->where('status', 'never_reported')->count(),
                'outdated' => $items->where('version_compliance', 'outdated')->count(),
                'pending_events' => $items->sum(fn (array $row) => (int) ($row['pending_event_count'] ?? 0)),
            ],
            'refresh_after_seconds' => 60,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }
}
