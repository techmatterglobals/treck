<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'user_id' => User::factory(),
            'department_id' => null,
            'employee_code' => 'EMP-'.fake()->unique()->numerify('#####'),
            'designation' => fake()->jobTitle(),
            'phone' => fake()->numerify('+1-###-###-####'),
            'joined_on' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
        ];
    }

    public function forOrganization(Organization|int $organization): static
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->state(fn () => ['organization_id' => $organizationId]);
    }
}
