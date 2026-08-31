<?php

namespace Tests\Feature\ApplicationUsage;

use App\DataObjects\AppUsageFilter;
use App\Enums\OrganizationRole;
use App\Livewire\ApplicationUsage\ApplicationUsageDashboard;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Reporting\ApplicationUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 7 — Application Usage dashboard: authorization, reporting queries and
 * Livewire rendering. Only administrators may view application usage.
 */
class ApplicationUsageDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('employee', 'web');
        $this->organization = Organization::factory()->create();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole('admin');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Admin);
        });
    }

    private function employee(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole('employee');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Employee);
        });
    }

    private function employeeRecord(array $attrs = []): Employee
    {
        return Employee::factory()->forOrganization($this->organization)->create($attrs);
    }

    private function computer(array $attrs = []): Computer
    {
        $employee = $attrs['employee_id'] ?? $this->employeeRecord()->id;

        return Computer::factory()
            ->forOrganization($this->organization)
            ->create(array_merge(['employee_id' => $employee], $attrs));
    }

    private function usage(array $attrs): ApplicationUsage
    {
        return ApplicationUsage::factory()->create($attrs);
    }

    // ---- Authorization -----------------------------------------------------

    public function test_guest_is_redirected(): void
    {
        $this->get('/application-usage')->assertRedirect('/login');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->employee())->get('/application-usage')->assertForbidden();
    }

    public function test_admin_can_view_the_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/application-usage')->assertOk();
    }

    public function test_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(ApplicationUsageDashboard::class)->assertForbidden();
    }

    // ---- Reporting queries -------------------------------------------------

    public function test_summary_totals_over_a_range(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Code', 'used_at' => today()->setTime(9, 0), 'ended_at' => today()->setTime(9, 10), 'duration_seconds' => 600, 'session_id' => 't1']);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Chrome', 'used_at' => today()->setTime(10, 0), 'ended_at' => today()->setTime(10, 5), 'duration_seconds' => 300, 'session_id' => 't2']);

        $summary = app(ApplicationUsageService::class)->summary(AppUsageFilter::fromArray([]));

        $this->assertSame(900, $summary['total_seconds']);
        $this->assertSame(2, $summary['sessions']);
        $this->assertSame(2, $summary['applications']);
        $this->assertSame('0h 15m', $summary['total_label']);
    }

    public function test_top_applications_are_ordered_by_total_time(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $base = ['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'used_at' => today()->setTime(9, 0)];
        $this->usage($base + ['application_name' => 'Chrome', 'duration_seconds' => 100, 'session_id' => 'c1']);
        $this->usage($base + ['application_name' => 'Code', 'duration_seconds' => 500, 'session_id' => 'v1']);
        $this->usage($base + ['application_name' => 'Code', 'duration_seconds' => 200, 'session_id' => 'v2']);

        $top = app(ApplicationUsageService::class)->topApplications(AppUsageFilter::fromArray([]));

        $this->assertSame('Code', $top->first()['application']);
        $this->assertSame(700, $top->first()['seconds']);
        $this->assertSame(2, $top->first()['sessions']);
    }

    public function test_per_employee_and_per_department_breakdowns(): void
    {
        $dept = Department::factory()->create(['name' => 'Engineering']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $employee->id, 'application_name' => 'Code', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 600, 'session_id' => 'e1']);

        $service = app(ApplicationUsageService::class);
        $perEmployee = $service->perEmployee(AppUsageFilter::fromArray([]));
        $perDept = $service->perDepartment(AppUsageFilter::fromArray([]));

        $this->assertSame($employee->name, $perEmployee->first()['employee']);
        $this->assertSame(600, $perEmployee->first()['seconds']);
        $this->assertSame('Engineering', $perDept->first()['department']);
        $this->assertSame(600, $perDept->first()['seconds']);
    }

    public function test_daily_usage_buckets_by_day(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Code', 'used_at' => today()->subDay()->setTime(9, 0), 'duration_seconds' => 300, 'session_id' => 'd1']);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Code', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 600, 'session_id' => 'd2']);

        $daily = app(ApplicationUsageService::class)->dailyUsage(AppUsageFilter::fromArray([
            'from' => today()->subDay()->toDateString(),
            'to' => today()->toDateString(),
        ]));

        $this->assertCount(2, $daily);
        $this->assertSame(300, $daily->first()['seconds']);
        $this->assertSame(600, $daily->last()['seconds']);
    }

    public function test_filters_narrow_by_employee_and_application(): void
    {
        $c1 = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $c2 = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->usage(['computer_id' => $c1->id, 'employee_id' => $c1->employee_id, 'application_name' => 'Code', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 100, 'session_id' => 'f1']);
        $this->usage(['computer_id' => $c2->id, 'employee_id' => $c2->employee_id, 'application_name' => 'Slack', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 200, 'session_id' => 'f2']);

        $service = app(ApplicationUsageService::class);

        $byEmployee = $service->summary(AppUsageFilter::fromArray(['employee_id' => $c1->employee_id]));
        $this->assertSame(100, $byEmployee['total_seconds']);

        $byApp = $service->summary(AppUsageFilter::fromArray(['application' => 'Slack']));
        $this->assertSame(200, $byApp['total_seconds']);
    }

    public function test_range_filter_excludes_out_of_range_sessions(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Code', 'used_at' => today()->subDays(30)->setTime(9, 0), 'duration_seconds' => 999, 'session_id' => 'old']);
        $this->usage(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'Code', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 111, 'session_id' => 'new']);

        $summary = app(ApplicationUsageService::class)->summary(AppUsageFilter::fromArray([
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]));

        $this->assertSame(111, $summary['total_seconds']);
    }

    // ---- Livewire rendering ------------------------------------------------

    public function test_dashboard_renders_sessions_for_admin(): void
    {
        $this->actingAs($this->admin());
        $computer = $this->computer();
        $this->usage(['organization_id' => $this->organization->id, 'computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'application_name' => 'MyEditor', 'used_at' => today()->setTime(9, 0), 'duration_seconds' => 300, 'session_id' => 'r1']);

        Livewire::test(ApplicationUsageDashboard::class)
            ->assertOk()
            ->assertSee('MyEditor');
    }

    public function test_dashboard_never_exposes_device_tokens(): void
    {
        $this->actingAs($this->admin());
        $computer = $this->computer(['paired_at' => now()]);
        $plain = $computer->createToken('agent', ['agent:report'])->plainTextToken;
        $this->usage(['organization_id' => $this->organization->id, 'computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'used_at' => today()->setTime(9, 0), 'session_id' => 'tok1']);

        $response = $this->get('/application-usage');
        $response->assertOk();
        $response->assertDontSee($plain);
        $this->assertStringNotContainsString('plainTextToken', $response->getContent());
    }
}
