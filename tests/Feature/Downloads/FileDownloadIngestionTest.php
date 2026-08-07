<?php

namespace Tests\Feature\Downloads;

use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerUser;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Services\Agent\AgentEventIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 12 — a file_download agent event is projected into file_downloads with
 * the right employee (via Phase 11 Windows-username resolution), is idempotent,
 * and stores metadata only.
 */
class FileDownloadIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('admin', 'web');
    }

    private function ingest(Computer $computer, array $payload, string $key): AgentEvent
    {
        return app(AgentEventIngestionService::class)->ingest($computer, [
            'kind' => 'file_download',
            'idempotency_key' => $key,
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode($payload),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'FileName' => 'report.pdf',
            'FileExtension' => 'pdf',
            'FileSize' => 2048,
            'LocalPath' => 'C:\\Users\\hassan\\Downloads\\report.pdf',
            'DownloadFolder' => 'C:\\Users\\hassan\\Downloads',
            'DownloadedAt' => now()->toIso8601String(),
            'ApplicationName' => 'chrome',
            'SourceUser' => 'hassan',
        ], $overrides);
    }

    public function test_download_event_is_projected_with_metadata(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        $this->ingest($computer, $this->payload(), 'dl-1');

        $download = FileDownload::first();
        $this->assertNotNull($download);
        $this->assertSame($employee->id, $download->employee_id);
        $this->assertSame('report.pdf', $download->file_name);
        $this->assertSame('pdf', $download->file_extension);
        $this->assertSame(2048, $download->file_size);
        $this->assertSame('hassan', $download->windows_username);
        $this->assertSame('chrome', $download->application_name);
    }

    public function test_download_resolves_shared_computer_user(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null]);
        $zain = Employee::factory()->create();
        ComputerUser::factory()->create([
            'computer_id' => $computer->id,
            'windows_username' => 'evening_user',
            'employee_id' => $zain->id,
        ]);

        $this->ingest($computer, $this->payload(['SourceUser' => 'evening_user']), 'dl-2');

        $this->assertSame($zain->id, FileDownload::first()->employee_id);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->ingest($computer, $this->payload(), 'dl-dupe');
        $this->ingest($computer, $this->payload(), 'dl-dupe'); // same idempotency key

        $this->assertSame(1, FileDownload::count());
    }

    public function test_extension_is_derived_from_name_when_absent(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->ingest($computer, $this->payload(['FileName' => 'archive.ZIP', 'FileExtension' => null]), 'dl-3');

        $this->assertSame('zip', FileDownload::first()->file_extension);
    }

    public function test_invalid_hash_is_discarded(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->ingest($computer, $this->payload(['Sha256Hash' => 'not-a-real-hash']), 'dl-4');

        $this->assertNull(FileDownload::first()->sha256_hash);
    }
}
