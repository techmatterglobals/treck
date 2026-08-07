<?php

namespace Database\Factories;

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        $type = fake()->randomElement(NotificationEventType::cases());
        $severity = $type->defaultSeverity();

        return [
            'recipient_id' => User::factory(),
            'computer_id' => null,
            'employee_id' => null,
            'event_type' => $type->value,
            'severity' => $severity->value,
            'title' => $type->label(),
            'message' => fake()->sentence(),
            'channel' => 'in_app',
            'dedupe_key' => $type->value.':'.fake()->numberBetween(1, 50),
            'status' => 'delivered',
            'delivered_at' => now(),
            'read_at' => null,
            'metadata' => [],
        ];
    }

    public function unread(): static
    {
        return $this->state(fn () => ['read_at' => null]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    public function severity(NotificationSeverity $severity): static
    {
        return $this->state(fn () => ['severity' => $severity->value]);
    }
}
