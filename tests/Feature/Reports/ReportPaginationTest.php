<?php

namespace Tests\Feature\Reports;

use App\Models\ActivityLog;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permission::findOrCreate('view reports', 'web');
    }

    private function viewer(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view reports'));
    }

    public function test_reports_index_paginates_at_50_rows_per_page(): void
    {
        // 55 distinct employees each with one activity log today (this month)
        // => 55 daily report rows => 2 pages of 50.
        ActivityLog::factory()->count(55)->create(['work_date' => today()->toDateString()]);

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
        ActivityLog::factory()->count(55)->create([
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
        ActivityLog::factory()->count(3)->create(['work_date' => today()->toDateString()]);

        $response = $this->actingAs($this->viewer())->get('/reports?period=weekly');
        $rows = $response->viewData('rows');

        $this->assertStringContainsString('period=weekly', $rows->url(1));
    }
}
