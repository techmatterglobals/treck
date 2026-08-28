<?php

namespace App\Services\Desktop;

use App\Models\User;
use App\Services\Hierarchy\EmployeeVisibility;
use App\Services\Presence\PresenceService;

class DesktopPresenceService
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly PresenceService $presence,
    ) {}

    /** @return array<string,mixed> */
    public function forUser(User $user): array
    {
        $rows = $this->presence->rows($this->visibility->computerIds($user))
            ->map(fn (array $row) => [
                'computer_id' => $row['computer_id'],
                'employee_id' => $row['employee_id'],
                'computer_name' => $row['computer_name'],
                'employee' => $row['employee'],
                'department' => $row['department'],
                'status' => $row['status']->value,
                'last_heartbeat_at' => $row['last_heartbeat_at']?->toIso8601String(),
                'last_activity_at' => $row['last_activity_at']?->toIso8601String(),
                'active_seconds' => $row['active_seconds'],
                'idle_seconds' => $row['idle_seconds'],
            ])
            ->values();

        return [
            'items' => $rows,
            'summary' => $this->presence->summary($this->visibility->computerIds($user)),
            'refresh_after_seconds' => 30,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }
}
