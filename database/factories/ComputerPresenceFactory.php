<?php

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComputerPresenceFactory extends Factory
{
    protected $model = ComputerPresence::class;

    public function definition(): array
    {
        $now = now();

        return [
            'organization_id' => null,
            'computer_id' => Computer::factory(),
            'status' => PresenceStatus::Active,
            'last_heartbeat_at' => $now,
            'last_activity_at' => $now,
            'last_event_at' => $now,
            'last_synced_at' => $now,
            'idle_seconds' => 0,
            'session_started_at' => $now->copy()->subHour(),
        ];
    }

    public function status(PresenceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** A machine that has not reported within the offline timeout. */
    public function stale(): static
    {
        $old = now()->subSeconds((int) config('treck.presence.offline_timeout_seconds', 180) + 60);

        return $this->state(fn () => [
            'last_heartbeat_at' => $old,
            'last_event_at' => $old,
            'last_synced_at' => $old,
        ]);
    }

    public function forOrganization(Organization|int $organization): static
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->state(fn () => ['organization_id' => $organizationId]);
    }

    public function forComputer(Computer $computer): static
    {
        return $this->state(fn () => [
            'organization_id' => $computer->organization_id,
            'computer_id' => $computer->id,
        ]);
    }
}
