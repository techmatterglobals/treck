<?php

namespace Database\Factories;

use App\Enums\AgentEventKind;
use App\Models\AgentEvent;
use App\Models\Computer;
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
}
