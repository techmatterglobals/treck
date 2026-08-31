<?php

namespace Database\Factories;

use App\Enums\ProductivityRating;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationUsageFactory extends Factory
{
    protected $model = ApplicationUsage::class;

    /** [name, executable, category, rating] */
    private const APPS = [
        ['Visual Studio Code', 'Code.exe', 'Development', ProductivityRating::Productive],
        ['PhpStorm', 'phpstorm64.exe', 'Development', ProductivityRating::Productive],
        ['Microsoft Excel', 'EXCEL.EXE', 'Office', ProductivityRating::Productive],
        ['Google Chrome', 'chrome.exe', 'Web', ProductivityRating::Neutral],
        ['Slack', 'slack.exe', 'Communication', ProductivityRating::Neutral],
        ['YouTube', null, 'Social Media', ProductivityRating::Unproductive],
        ['Facebook', null, 'Social Media', ProductivityRating::Unproductive],
    ];

    public function definition(): array
    {
        [$name, $exe, $category, $rating] = fake()->randomElement(self::APPS);

        $usedAt = fake()->dateTimeBetween('-14 days', 'now');
        $duration = fake()->numberBetween(300, 3600);

        return [
            'organization_id' => null,
            'employee_id' => Employee::factory(),
            'computer_id' => Computer::factory(),
            'activity_log_id' => null,
            'application_name' => $name,
            'executable' => $exe,
            'window_title' => fake()->sentence(4),
            'category' => $category,
            'productivity' => $rating,
            'used_at' => $usedAt,
            'ended_at' => (clone $usedAt)->modify("+{$duration} seconds"),
            'duration_seconds' => $duration,
            'session_id' => (string) fake()->uuid(),
        ];
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
