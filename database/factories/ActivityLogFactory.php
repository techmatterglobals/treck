<?php

namespace Database\Factories;

use App\Enums\ComputerStatus;
use App\Enums\SessionEndReason;
use App\Models\ActivityLog;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        $login = Carbon::instance(fake()->dateTimeBetween('-14 days', 'now'))->setTime(9, fake()->numberBetween(0, 45));
        $active = fake()->numberBetween(3 * 3600, 7 * 3600);
        $idle = fake()->numberBetween(600, 2 * 3600);

        return [
            'employee_id' => Employee::factory(),
            'computer_id' => Computer::factory(),
            'login_at' => $login,
            'logout_at' => $login->copy()->addSeconds($active + $idle),
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'status' => ComputerStatus::Offline,
            'end_reason' => SessionEndReason::Logout,
            'work_date' => $login->toDateString(),
        ];
    }
}
