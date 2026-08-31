<?php

namespace Tests\Feature\Reports;

use App\Enums\OrganizationRole;
use App\Models\ActivityLog;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Reports index is paginated (50/page) and pagination preserves the filters.
 */
class ReportPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permission::findOrCreate('view reports', 'web');
        $this->organization = Organization::factory()->create();
    }

    private function viewer(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->givePermissionTo('view reports');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Admin);
        });
    }

    private function activityLog(array $attrs = []): ActivityLog
    {
        $employee = Employee::factory()->forOrganization($this->organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();

        return ActivityLog::factory()->forComputer($computer)->create($attrs);
    }

    private function activityLogs(int $count, array $attrs = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->activityLog($attrs);
        }
    }

    public function test_reports_index_paginates_at_50_rows_per_page(): void
    {
        // 55 distinct employees each with one activity log today (this month)
        // => 55 daily report rows => 2 pages of 50.
        $this->activityLogs(55, ['work_date' => today()->toDateString()]);

        $response = $this->actingAs($this->viewer())->get('/reports');
        $response->assertOk();

        $rows = $response->viewData('rows');
        $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
        $this->assertSame(50, $rows->perPage());
        $this->assertSame(55, $rows->total());
        $this->assertSame(2, $rows->lastPage());
    }

    public function test_totals_cover_the_full_range_not_just_the_page(): void
    {
        $this->activityLogs(55, [
            'work_date' => today()->toDateString(),
            'active_seconds' => 100,
            'idle_seconds' => 0,
        ]);

        $response = $this->actingAs($this->viewer())->get('/reports');

        $totals = $response->viewData('totals');
        $this->assertSame(55, $totals['rows']);               // total groups, not page size
        $this->assertSame(55 * 100, $totals['active_seconds']); // summed across all pages
    }

    public function test_pagination_links_preserve_filters(): void
    {
        $this->activityLogs(3, ['work_date' => today()->toDateString()]);

        $response = $this->actingAs($this->viewer())->get('/reports?period=weekly');
        $rows = $response->viewData('rows');

        $this->assertStringContainsString('period=weekly', $rows->url(1));
    }
}
