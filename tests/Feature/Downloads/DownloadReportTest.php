<?php

namespace Tests\Feature\Downloads;

use App\DataObjects\DownloadFilter;
use App\Enums\OrganizationRole;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\Organization;
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

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Role::findOrCreate('admin', 'web');
        $this->organization = Organization::factory()->create();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole('admin');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Admin);
        });
    }

    private function seedDownloads(): void
    {
        $employee = Employee::factory()->forOrganization($this->organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        foreach (['a.exe' => 'exe', 'b.exe' => 'exe', 'c.pdf' => 'pdf'] as $name => $ext) {
            FileDownload::factory()->forComputer($computer)->create([
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
