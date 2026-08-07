<?php

namespace Database\Factories;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\Screenshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScreenshotFactory extends Factory
{
    protected $model = Screenshot::class;

    /** [process, window title] */
    private const APPS = [
        ['Code.exe', 'ScreenshotService.php — treck'],
        ['chrome.exe', 'Inbox (3) - Gmail'],
        ['EXCEL.EXE', 'Q3 Forecast.xlsx'],
        ['slack.exe', '#engineering - Treck'],
    ];

    public function definition(): array
    {
        [$process, $title] = fake()->randomElement(self::APPS);

        $capturedAt = fake()->dateTimeBetween('-14 days', 'now');
        $hash = hash('sha256', (string) fake()->unique()->uuid());
        $width = fake()->randomElement([1920, 2560, 1366]);
        $height = fake()->randomElement([1080, 1440, 768]);

        return [
            'employee_id' => Employee::factory(),
            'computer_id' => Computer::factory(),
            'activity_log_id' => null,
            'path' => "screenshots/1/{$hash}.jpg",
            'thumbnail_path' => null,
            'captured_at' => $capturedAt,
            'disk' => 'local',
            'filename' => $hash.'.jpg',
            'image_hash' => $hash,
            'monitor_number' => fake()->numberBetween(0, 1),
            'width' => $width,
            'height' => $height,
            'file_size' => fake()->numberBetween(40_000, 400_000),
            'active_process' => $process,
            'active_window_title' => $title,
            'session_id' => (string) fake()->uuid(),
        ];
    }
}
