<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\DataObjects\AppUsageFilter;
use App\DataObjects\DownloadFilter;
use App\DataObjects\ReportFilter;
use App\Enums\AgentEventKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\PlatformRole;
use App\Enums\PresenceStatus;
use App\Livewire\ApplicationUsage\ApplicationUsageDashboard;
use App\Livewire\Downloads\FileDownloadDashboard;
use App\Livewire\Notifications\NotificationDashboard;
use App\Livewire\Presence\PresenceBoard;
use App\Livewire\Screenshots\ScreenshotDashboard;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\ApplicationUsage;
use App\Models\Attendance;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductivityReport;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Agent\AgentEventIngestionService;
use App\Services\Reporting\ApplicationUsageService;
use App\Services\Reporting\FileDownloadService;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseB2MonitoringTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_phase_b2_tables_have_nullable_indexed_organization_ownership(): void
    {
        foreach ($this->ownedTables() as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table.' is missing organization_id.');

            $column = Schema::getColumnType($table, 'organization_id');
            $this->assertContains($column, ['integer', 'bigint'], $table.' organization_id type is unexpected.');
        }
    }

    public function test_agent_ingestion_derives_organization_from_computer_and_ignores_payload_organization(): void
    {
        [$organization, $employee, $computer] = $this->ownedComputer();
        $other = Organization::factory()->create();

        $event = app(AgentEventIngestionService::class)->ingest($computer, [
            'kind' => AgentEventKind::Heartbeat->value,
            'idempotency_key' => 'heartbeat-one',
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode([
                'organization_id' => $other->id,
                'ActiveSeconds' => 30,
                'IdleSeconds' => 5,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($employee->id, $event->employee_id);
        $this->assertSame($organization->id, $computer->presence()->first()->organization_id);
    }

    public function test_unowned_computer_leaves_monitoring_ownership_null(): void
    {
        $computer = Computer::factory()->create(['organization_id' => null, 'employee_id' => null]);

        $event = app(AgentEventIngestionService::class)->ingest($computer, [
            'kind' => AgentEventKind::Heartbeat->value,
            'idempotency_key' => 'heartbeat-unowned',
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode(['ActiveSeconds' => 10, 'IdleSeconds' => 0], JSON_THROW_ON_ERROR),
        ]);

        $this->assertNull($event->organization_id);
        $this->assertNull($computer->presence()->first()->organization_id);
    }

    public function test_monitoring_dashboards_show_current_organization_rows_only(): void
    {
        [$first, , $firstComputer] = $this->ownedComputer();
        [$second, , $secondComputer] = $this->ownedComputer();
        $admin = $this->actingAsOrganizationRole($first, OrganizationRole::Admin);
        $firstComputer->forceFill(['hostname' => 'VISIBLE-APP-PC'])->save();
        $secondComputer->forceFill(['hostname' => 'HIDDEN-APP-PC'])->save();

        $this->usageFor($firstComputer, 'VisibleEditor');
        $this->usageFor($secondComputer, 'HiddenEditor');
        Screenshot::factory()->forComputer($firstComputer)->create([
            'active_window_title' => 'Visible Window',
            'captured_at' => today()->setTime(10, 0),
        ]);
        Screenshot::factory()->forComputer($secondComputer)->create([
            'active_window_title' => 'Hidden Window',
            'captured_at' => today()->setTime(10, 0),
        ]);
        FileDownload::factory()->forComputer($firstComputer)->create(['file_name' => 'visible.pdf']);
        FileDownload::factory()->forComputer($secondComputer)->create(['file_name' => 'hidden.pdf']);

        Livewire::actingAs($admin)->test(ApplicationUsageDashboard::class)
            ->assertSee('VisibleEditor')
            ->assertDontSee('HiddenEditor');

        Livewire::actingAs($admin)->test(ScreenshotDashboard::class)
            ->assertSee('VISIBLE-APP-PC')
            ->assertDontSee('HIDDEN-APP-PC');

        Livewire::actingAs($admin)->test(FileDownloadDashboard::class)
            ->assertSee('visible.pdf')
            ->assertDontSee('hidden.pdf');
    }

    public function test_presence_board_hides_null_and_foreign_owned_rows(): void
    {
        [$organization, , $computer] = $this->ownedComputer();
        [, , $foreignComputer] = $this->ownedComputer();
        $unowned = Computer::factory()->create(['organization_id' => null, 'employee_id' => null, 'hostname' => 'NULL-PC']);
        $admin = $this->actingAsOrganizationRole($organization, OrganizationRole::Admin);
        $computer->forceFill(['hostname' => 'VISIBLE-PC'])->save();
        $foreignComputer->forceFill(['hostname' => 'FOREIGN-PC'])->save();

        ComputerPresence::factory()->forComputer($computer)->status(PresenceStatus::Active)->create();
        ComputerPresence::factory()->forComputer($foreignComputer)->status(PresenceStatus::Active)->create();
        ComputerPresence::factory()->for($unowned)->status(PresenceStatus::Active)->create(['organization_id' => null]);

        Livewire::actingAs($admin)->test(PresenceBoard::class)
            ->assertSee('VISIBLE-PC')
            ->assertDontSee('FOREIGN-PC')
            ->assertDontSee('NULL-PC');
    }

    public function test_cross_organization_monitoring_detail_routes_fail_safely(): void
    {
        [$organization] = $this->ownedComputer();
        [, , $foreignComputer] = $this->ownedComputer();
        $admin = $this->actingAsOrganizationRole($organization, OrganizationRole::Admin);
        $screenshot = Screenshot::factory()->forComputer($foreignComputer)->create();
        $download = FileDownload::factory()->forComputer($foreignComputer)->create();

        $this->actingAs($admin)->get(route('screenshots.show', $screenshot))->assertNotFound();
        $this->actingAs($admin)->get(route('downloads.show', $download))->assertNotFound();
    }

    public function test_manager_monitoring_access_is_restricted_to_assigned_employees(): void
    {
        [$organization, $assigned, $assignedComputer] = $this->ownedComputer();
        [, , $peerComputer] = $this->ownedComputer($organization);
        $manager = $this->actingAsOrganizationRole($organization, OrganizationRole::Manager);
        $assigned->forceFill(['manager_user_id' => $manager->id])->save();

        $this->usageFor($assignedComputer, 'AssignedApp');
        $this->usageFor($peerComputer, 'PeerApp');

        Livewire::actingAs($manager)->test(ApplicationUsageDashboard::class)
            ->assertSee('AssignedApp')
            ->assertDontSee('PeerApp');
    }

    public function test_membership_without_scoped_role_and_legacy_role_fail_closed(): void
    {
        [$organization] = $this->ownedComputer();
        $memberWithoutScopedRole = User::factory()->create();
        $legacyAdmin = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $legacyAdmin->assignRole('admin');

        $this->grantOrganizationRole($memberWithoutScopedRole, $organization, OrganizationRole::Employee);
        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $legacyAdmin->id,
            'status' => MembershipStatus::Active,
            'role' => 'legacy-admin-without-scoped-role',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($memberWithoutScopedRole)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/presence')
            ->assertForbidden();
        $this->actingAs($legacyAdmin)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/presence')
            ->assertForbidden();
    }

    public function test_current_organization_context_does_not_leak_between_component_requests(): void
    {
        [$first, , $firstComputer] = $this->ownedComputer();
        [$second, , $secondComputer] = $this->ownedComputer();
        $firstAdmin = $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Admin);
        $secondAdmin = $this->grantOrganizationRole(User::factory()->create(), $second, OrganizationRole::Admin);
        $this->usageFor($firstComputer, 'FirstOrgApp');
        $this->usageFor($secondComputer, 'SecondOrgApp');

        Livewire::actingAs($firstAdmin)->test(ApplicationUsageDashboard::class)
            ->assertSee('FirstOrgApp')
            ->assertDontSee('SecondOrgApp');

        Livewire::actingAs($secondAdmin)->test(ApplicationUsageDashboard::class)
            ->assertSee('SecondOrgApp')
            ->assertDontSee('FirstOrgApp');
    }

    public function test_dashboard_kpis_reports_and_download_exports_are_organization_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 18:00:00', 'UTC'));

        try {
            [$organization, , $computer] = $this->ownedComputer();
            [, , $foreignComputer] = $this->ownedComputer();
            $this->actingAsOrganizationRole($organization, OrganizationRole::Admin);

            $reportDate = '2026-08-31';

            ActivityLog::factory()->forComputer($computer)->create([
                'active_seconds' => 100,
                'idle_seconds' => 50,
                'login_at' => "{$reportDate} 09:00:00",
                'logout_at' => "{$reportDate} 09:02:30",
                'work_date' => $reportDate,
            ]);
            ActivityLog::factory()->forComputer($foreignComputer)->create([
                'active_seconds' => 999,
                'idle_seconds' => 999,
                'login_at' => "{$reportDate} 10:00:00",
                'logout_at' => "{$reportDate} 10:33:18",
                'work_date' => $reportDate,
            ]);
            FileDownload::factory()->forComputer($computer)->create([
                'file_name' => 'visible-export.pdf',
                'downloaded_at' => "{$reportDate} 09:05:00",
            ]);
            FileDownload::factory()->forComputer($foreignComputer)->create([
                'file_name' => 'hidden-export.pdf',
                'downloaded_at' => "{$reportDate} 10:05:00",
            ]);

            $reportFilter = ReportFilter::fromArray(['from' => $reportDate, 'to' => $reportDate])
                ->forOrganization($organization->id);
            $downloadFilter = DownloadFilter::fromArray(['from' => $reportDate, 'to' => $reportDate])
                ->forOrganization($organization->id);

            $this->assertCount(1, app(ReportService::class)->build($reportFilter));
            $this->assertSame(1, app(FileDownloadService::class)->query($downloadFilter)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_notification_inbox_is_current_organization_scoped(): void
    {
        [$first] = $this->ownedComputer();
        [$second] = $this->ownedComputer();
        $admin = User::factory()->create();
        $this->grantOrganizationRole($admin, $first, OrganizationRole::Admin);

        NotificationLog::factory()->forOrganization($first)->create([
            'recipient_id' => $admin->id,
            'title' => 'Visible alert',
        ]);
        NotificationLog::factory()->forOrganization($second)->create([
            'recipient_id' => $admin->id,
            'title' => 'Hidden alert',
        ]);

        $this->actingAs($admin);
        session([CurrentOrganization::SESSION_KEY => $first->id]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->assertSee('Visible alert')
            ->assertDontSee('Hidden alert');
    }

    public function test_backfill_supports_dry_run_real_execution_and_verify_without_role_assignment(): void
    {
        [$organization, , $computer] = $this->ownedComputer();
        $event = AgentEvent::factory()->forComputer($computer)->create(['organization_id' => null]);
        $presence = ComputerPresence::factory()->forComputer($computer)->create(['organization_id' => null]);
        $attendance = Attendance::create([
            'employee_id' => $computer->employee_id,
            'work_date' => today(),
            'first_in_at' => now(),
            'last_out_at' => now(),
            'work_seconds' => 10,
            'active_seconds' => 10,
            'idle_seconds' => 0,
            'status' => 'present',
        ]);
        $report = ProductivityReport::create([
            'employee_id' => $computer->employee_id,
            'period_type' => 'daily',
            'period_date' => today(),
            'active_seconds' => 10,
            'productive_seconds' => 10,
            'unproductive_seconds' => 0,
            'neutral_seconds' => 0,
            'productivity_score' => 100,
        ]);

        $this->artisan('treck:backfill-monitoring-organization-ownership', [
            '--organization' => (string) $organization->id,
            '--dry-run' => true,
        ])->expectsOutput('platform_super_admin_assignments=0')->assertSuccessful();

        $this->assertNull($event->fresh()->organization_id);
        $this->assertNull($presence->fresh()->organization_id);
        $this->assertNull($attendance->fresh()->organization_id);
        $this->assertNull($report->fresh()->organization_id);

        $this->artisan('treck:backfill-monitoring-organization-ownership', [
            '--organization' => (string) $organization->id,
        ])->assertSuccessful();

        $this->assertSame($organization->id, $event->fresh()->organization_id);
        $this->assertSame($organization->id, $presence->fresh()->organization_id);
        $this->assertSame($organization->id, $attendance->fresh()->organization_id);
        $this->assertSame($organization->id, $report->fresh()->organization_id);
        $this->assertFalse(Role::query()->where('name', PlatformRole::SuperAdmin->value)->exists());

        $this->artisan('treck:backfill-monitoring-organization-ownership', [
            '--organization' => (string) $organization->id,
            '--verify' => true,
        ])->assertSuccessful();
    }

    public function test_backfill_reports_conflicts_and_does_not_overwrite_existing_ownership(): void
    {
        [$first, $firstEmployee, $firstComputer] = $this->ownedComputer();
        [, $secondEmployee] = $this->ownedComputer();
        $conflicted = ActivityLog::factory()->create([
            'organization_id' => null,
            'computer_id' => $firstComputer->id,
            'employee_id' => $secondEmployee->id,
        ]);
        $owned = ActivityLog::factory()->create([
            'organization_id' => $first->id,
            'computer_id' => $firstComputer->id,
            'employee_id' => $firstEmployee->id,
        ]);

        $this->artisan('treck:backfill-monitoring-organization-ownership', [
            '--organization' => (string) $first->id,
            '--verify' => true,
        ])->assertFailed();

        $this->artisan('treck:backfill-monitoring-organization-ownership', [
            '--organization' => (string) $first->id,
        ])->assertSuccessful();

        $this->assertNull($conflicted->fresh()->organization_id);
        $this->assertSame($first->id, $owned->fresh()->organization_id);
    }

    public function test_lower_level_services_can_still_query_unscoped_rows_when_no_tenant_filter_is_passed(): void
    {
        ApplicationUsage::factory()->create([
            'organization_id' => null,
            'application_name' => 'LegacyApp',
            'used_at' => today()->setTime(10, 0),
            'ended_at' => today()->setTime(10, 5),
            'duration_seconds' => 300,
        ]);

        $summary = app(ApplicationUsageService::class)->summary(AppUsageFilter::fromArray([]));

        $this->assertSame(1, $summary['sessions']);
    }

    /**
     * @return list<string>
     */
    private function ownedTables(): array
    {
        return [
            'agent_events',
            'agent_health_reports',
            'computer_presence',
            'activity_logs',
            'application_usage',
            'screenshots',
            'file_downloads',
            'attendance',
            'productivity_reports',
            'notification_logs',
        ];
    }

    /**
     * @return array{0:Organization,1:Employee,2:Computer}
     */
    private function ownedComputer(?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();

        return [$organization, $employee, $computer];
    }

    private function usageFor(Computer $computer, string $application): ApplicationUsage
    {
        return ApplicationUsage::factory()->forComputer($computer)->create([
            'application_name' => $application,
            'used_at' => today()->setTime(9, 0),
            'ended_at' => today()->setTime(9, 10),
            'duration_seconds' => 600,
            'session_id' => 'session-'.$application,
        ]);
    }
}
