<?php

namespace Database\Factories;

use App\Enums\ProductivityRating;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Employee;
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

        return [
            'employee_id' => Employee::factory(),
            'computer_id' => Computer::factory(),
            'activity_log_id' => null,
            'application_name' => $name,
            'executable' => $exe,
            'window_title' => fake()->sentence(4),
            'category' => $category,
            'productivity' => $rating,
            'used_at' => fake()->dateTimeBetween('-14 days', 'now'),
            'duration_seconds' => fake()->numberBetween(300, 3600),
        ];
    }
}
