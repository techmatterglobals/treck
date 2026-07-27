<?php

namespace Database\Factories;

use App\Models\Computer;
use App\Models\ComputerUser;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComputerUserFactory extends Factory
{
    protected $model = ComputerUser::class;

    public function definition(): array
    {
        return [
            'computer_id' => Computer::factory(),
            'employee_id' => Employee::factory(),
            'windows_username' => fake()->unique()->userName(),
            'last_seen_at' => now(),
            'last_login_at' => now(),
            'last_logout_at' => null,
            'is_active' => true,
        ];
    }

    /** A pending (unresolved) mapping with no employee yet. */
    public function pending(): static
    {
        return $this->state(fn () => ['employee_id' => null]);
    }
}
