<?php

namespace Tests\Feature\Downloads;

use App\DataObjects\DownloadFilter;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\User;
use App\Services\Reporting\FileDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 12 — download reports (grouped aggregates) and Excel/CSV export.
 */
class DownloadReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Role::findOrCreate('admin', 'web');
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private function seedDownloads(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);
        foreach (['a.exe' => 'exe', 'b.exe' => 'exe', 'c.pdf' => 'pdf'] as $name => $ext) {
            FileDownload::factory()->create([
                'employee_id' => $employee->id,
                'computer_id' => $computer->id,
                'file_name' => $name,
                'file_extension' => $ext,
                'file_size' => 1000,
                'downloaded_at' => now(),
            ]);
        }
    }

    public function test_report_groups_by_extension(): void
    {
        $this->seedDownloads();

        $rows = app(FileDownloadService::class)->report(
            DownloadFilter::fromArray(['from' => today()->toDateString(), 'to' => today()->toDateString()]),
            'extension',
        );

        $exe = $rows->firstWhere('group', 'exe');
        $this->assertNotNull($exe);
        $this->assertSame(2, (int) $exe->downloads);
    }

    public function test_export_returns_a_spreadsheet(): void
    {
        $this->seedDownloads();

        $response = $this->actingAs($this->admin())->get(route('downloads.export', ['format' => 'xlsx']));

        $response->assertOk();
        $this->assertStringContainsString('treck-downloads', $response->headers->get('content-disposition') ?? '');
    }

    public function test_reports_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin())->get(route('downloads.reports', ['dimension' => 'employee']))->assertOk();
    }
}
