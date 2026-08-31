<?php

namespace Database\Factories;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class FileDownloadFactory extends Factory
{
    protected $model = FileDownload::class;

    public function definition(): array
    {
        $ext = fake()->randomElement(['pdf', 'docx', 'xlsx', 'zip', 'exe', 'csv', 'png']);
        $name = fake()->word().'.'.$ext;

        return [
            'organization_id' => null,
            'computer_id' => Computer::factory(),
            'employee_id' => Employee::factory(),
            'windows_username' => fake()->userName(),
            'application_name' => fake()->randomElement(['chrome', 'msedge', 'firefox', 'outlook']),
            'process_name' => 'chrome',
            'window_title' => fake()->sentence(3),
            'file_name' => $name,
            'file_extension' => $ext,
            'file_size' => fake()->numberBetween(1024, 50 * 1024 * 1024),
            'local_path' => 'C:\\Users\\user\\Downloads\\'.$name,
            'download_folder' => 'C:\\Users\\user\\Downloads',
            'sha256_hash' => fake()->optional()->sha256(),
            'downloaded_at' => now(),
            'session_id' => (string) fake()->uuid(),
            'event_key' => (string) fake()->unique()->uuid(),
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
