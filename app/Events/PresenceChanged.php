<?php

namespace App\Events;

use App\Models\ComputerPresence;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a computer's presence changes (Phase 6). Carries a compact,
 * secret-free snapshot so the admin dashboard updates live without polling.
 *
 * Broadcast on two private, organization-scoped channels:
 *   - `organization.{id}.presence`
 *   - `organization.{id}.presence.computer.{computer_id}`
 *
 * Device tokens, provisioning keys and other credentials are never included.
 */
class PresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ComputerPresence $presence) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $organizationId = $this->organizationId();

        if ($organizationId === null) {
            return [];
        }

        return [
            new PrivateChannel('organization.'.$organizationId.'.presence'),
            new PrivateChannel('organization.'.$organizationId.'.presence.computer.'.$this->presence->computer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PresenceChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->presence->loadMissing('computer.employee.department');

        $computer = $this->presence->computer;
        $employee = $computer?->employee;
        $status = $this->presence->status;

        return [
            'organization_id' => $this->organizationId(),
            'computer_id' => $this->presence->computer_id,
            'computer_name' => $computer?->hostname,
            'employee' => $employee?->name,
            'department' => $employee?->department?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'is_online' => $status->isOnline(),
            'idle_seconds' => $this->presence->idle_seconds,
            'last_heartbeat_at' => $this->presence->last_heartbeat_at?->toIso8601String(),
            'last_activity_at' => $this->presence->last_activity_at?->toIso8601String(),
            'last_synced_at' => $this->presence->last_synced_at?->toIso8601String(),
        ];
    }

    private function organizationId(): ?int
    {
        $organizationId = $this->presence->organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
