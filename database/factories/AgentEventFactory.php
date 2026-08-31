<?php

namespace Database\Factories;

use App\Enums\AgentEventKind;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AgentEventFactory extends Factory
{
    protected $model = AgentEvent::class;

    public function definition(): array
    {
        $occurredAt = now()->subMinutes(fake()->numberBetween(0, 120));
        $computer = Computer::factory();

        return [
            'organization_id' => null,
            'computer_id' => $computer,
            'employee_id' => fn (array $attrs) => Computer::find($attrs['computer_id'])?->employee_id,
            'kind' => fake()->randomElement(AgentEventKind::cases()),
            'idempotency_key' => Str::random(32),
            'payload' => [
                'TimestampUtc' => $occurredAt->toIso8601String(),
                'ElapsedSeconds' => 60,
                'ActiveSeconds' => 45,
                'IdleSeconds' => 15,
            ],
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addSeconds(fake()->numberBetween(0, 30)),
        ];
    }

    public function heartbeat(): static
    {
        return $this->state(fn () => ['kind' => AgentEventKind::Heartbeat]);
    }

    public function session(): static
    {
        return $this->state(fn () => [
            'kind' => AgentEventKind::Session,
            'payload' => ['Type' => 'Logon', 'TimestampUtc' => now()->toIso8601String()],
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
            'employee_id' => $computer->employee_id,
        ]);
    }
}
