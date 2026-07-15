<?php

namespace Database\Seeders;

use App\Enums\ComputerStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use App\Services\Productivity\ProductivityService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds realistic demo data — departments, employees (with users + the employee
 * role), computers, ~2 weeks of PC sessions and app usage — then runs the
 * rollups so attendance and productivity_reports are populated for the
 * dashboard and reports. For local/demo use, not production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate(UserRole::Employee->value, 'web');

        $departments = Department::factory()->count(4)->create();

        Employee::factory()
            ->count(15)
            ->create()
            ->each(function (Employee $employee) use ($departments) {
                $employee->update(['department_id' => $departments->random()->id]);
                $employee->user->assignRole(UserRole::Employee->value);

                $computer = Computer::factory()->create(['employee_id' => $employee->id]);

                $this->seedSessions($employee, $computer);
            });

        // Put a handful of computers "online now" for the live widgets.
        Computer::inRandomOrder()->limit(6)->get()->each(
            fn (Computer $c) => $c->update([
                'status' => ComputerStatus::Online,
                'last_seen_at' => now(),
                'last_activity_at' => now(),
            ]),
        );

        $this->runRollups();
    }

    private function seedSessions(Employee $employee, Computer $computer): void
    {
        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $date = today()->subDays($daysAgo);

            if ($date->isWeekend()) {
                continue;
            }

            $login = $date->copy()->setTime(9, fake()->numberBetween(0, 50));
            $active = fake()->numberBetween(3 * 3600, 7 * 3600);
            $idle = fake()->numberBetween(600, 2 * 3600);

            $log = ActivityLog::factory()->create([
                'employee_id' => $employee->id,
                'computer_id' => $computer->id,
                'login_at' => $login,
                'logout_at' => $login->copy()->addSeconds($active + $idle),
                'active_seconds' => $active,
                'idle_seconds' => $idle,
                'work_date' => $date->toDateString(),
            ]);

            ApplicationUsage::factory()
                ->count(fake()->numberBetween(2, 5))
                ->create([
                    'employee_id' => $employee->id,
                    'computer_id' => $computer->id,
                    'activity_log_id' => $log->id,
                    'used_at' => $login->copy()->addMinutes(fake()->numberBetween(0, 300)),
                ]);
        }
    }

    private function runRollups(): void
    {
        $attendance = app(AttendanceService::class);
        $productivity = app(ProductivityService::class);

        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $date = today()->subDays($daysAgo);
            $attendance->deriveDaily($date);
            $productivity->generateDaily($date);
        }
    }
}
