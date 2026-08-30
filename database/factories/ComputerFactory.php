<?php

namespace Database\Factories;

use App\Enums\ComputerStatus;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComputerFactory extends Factory
{
    protected $model = Computer::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'employee_id' => Employee::factory(),
            'device_uuid' => fake()->unique()->uuid(),
            'hostname' => Str::upper(fake()->word()).'-PC',
            'os' => fake()->randomElement(['Windows 11', 'Windows 10']),
            'agent_version' => '1.0.0',
            'status' => ComputerStatus::Offline,
            'last_seen_at' => null,
            'last_activity_at' => null,
            'paired_at' => now(),
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status' => ComputerStatus::Online,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    public function forOrganization(Organization|int $organization): static
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->state(fn () => ['organization_id' => $organizationId]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
        ]);
    }
}
