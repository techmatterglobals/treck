<?php

namespace Tests\Feature\Downloads;

use App\Enums\NotificationEventType;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 12 — download alert rules fire through the reused notification engine
 * (executable / archive / large / restricted), and are configurable.
 */
class DownloadNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('admin', 'web');
        // An admin recipient must exist for the engine to persist a log.
        tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private function dispatch(array $data): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        app(NotificationEngine::class)->dispatch(new NotificationContext(
            source: 'download',
            data: $data,
            computer: $computer,
            employee: $employee,
        ));
    }

    public function test_executable_download_raises_a_critical_alert(): void
    {
        $this->dispatch(['file_name' => 'setup.exe', 'file_extension' => 'exe', 'file_size' => 1000, 'application_name' => 'chrome']);

        $log = NotificationLog::where('event_type', NotificationEventType::DownloadExecutable->value)->first();
        $this->assertNotNull($log);
        $this->assertSame('critical', $log->severity);
    }

    public function test_archive_download_raises_a_warning(): void
    {
        $this->dispatch(['file_name' => 'bundle.zip', 'file_extension' => 'zip', 'file_size' => 1000]);

        $this->assertTrue(
            NotificationLog::where('event_type', NotificationEventType::DownloadArchive->value)->exists()
        );
    }

    public function test_large_file_download_raises_a_warning(): void
    {
        config(['treck.downloads.large_file_bytes' => 1000]);

        $this->dispatch(['file_name' => 'movie.mp4', 'file_extension' => 'mp4', 'file_size' => 5000]);

        $this->assertTrue(
            NotificationLog::where('event_type', NotificationEventType::DownloadLarge->value)->exists()
        );
    }

    public function test_restricted_extension_is_configurable(): void
    {
        NotificationRule::where('event_type', NotificationEventType::DownloadRestricted->value)
            ->update(['config' => ['extensions' => ['iso']]]);

        $this->dispatch(['file_name' => 'ubuntu.iso', 'file_extension' => 'iso', 'file_size' => 1000]);

        $this->assertTrue(
            NotificationLog::where('event_type', NotificationEventType::DownloadRestricted->value)->exists()
        );
    }

    public function test_ordinary_download_raises_no_alert(): void
    {
        $this->dispatch(['file_name' => 'notes.txt', 'file_extension' => 'txt', 'file_size' => 100]);

        $this->assertSame(0, NotificationLog::count());
    }
}
