<?php

namespace Tests\Feature\Tenancy;

use App\Enums\NotificationSeverity;
use App\Enums\OrganizationRole;
use App\Events\NotificationCreated;
use App\Events\PresenceChanged;
use App\Jobs\RollUpDailyAttendance;
use App\Models\ActivityLog;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Notifications\Channels\InAppChannel;
use App\Services\Notifications\NotificationPreferenceResolver;
use App\Support\Tenancy\TenantCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseB5InfrastructureTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cache_keys_are_partitioned_and_platform_keys_are_explicit(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $this->assertSame("org:{$first->id}:presence:summary", TenantCacheKey::forOrganization($first, 'presence summary'));
        $this->assertSame("org:{$second->id}:presence:summary", TenantCacheKey::forOrganization($second, 'presence summary'));
        $this->assertSame('platform:release:metadata', TenantCacheKey::platform('release metadata'));
    }

    public function test_broadcast_events_use_organization_scoped_channels_and_payloads(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        $presence = ComputerPresence::factory()->for($computer)->forOrganization($organization)->create();

        $presenceEvent = new PresenceChanged($presence);
        $this->assertSame([
            "private-organization.{$organization->id}.presence",
            "private-organization.{$organization->id}.presence.computer.{$computer->id}",
        ], array_map('strval', $presenceEvent->broadcastOn()));
        $this->assertSame($organization->id, $presenceEvent->broadcastWith()['organization_id']);

        $log = NotificationLog::factory()->forOrganization($organization)->create(['recipient_id' => User::factory()->create()->id]);
        $notificationEvent = new NotificationCreated($log);

        $this->assertSame([
            "private-organization.{$organization->id}.notifications.user.{$log->recipient_id}",
        ], array_map('strval', $notificationEvent->broadcastOn()));
        $this->assertSame($organization->id, $notificationEvent->broadcastWith()['organization_id']);
    }

    public function test_notification_recipients_are_limited_to_scoped_organization_admins(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $firstAdmin = $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Admin);
        $this->grantOrganizationRole(User::factory()->create(), $second, OrganizationRole::Admin);
        $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Manager);

        $recipients = app(NotificationPreferenceResolver::class)->recipients(
            NotificationSeverity::Critical,
            [InAppChannel::KEY],
            $first->id,
        );

        $this->assertSame([$firstAdmin->id], collect($recipients)->pluck('user.id')->all());
    }

    public function test_rollup_jobs_run_inside_organization_context_and_clear_afterward(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create();
        ActivityLog::factory()
            ->forOrganization($organization)
            ->create([
                'employee_id' => $employee->id,
                'work_date' => today()->toDateString(),
                'login_at' => now()->setTime(9, 0),
                'logout_at' => now()->setTime(17, 0),
            ]);

        RollUpDailyAttendance::dispatchSync(today()->toDateString(), $organization->id);

        $this->assertDatabaseHas('attendance', [
            'organization_id' => $organization->id,
            'employee_id' => $employee->id,
            'work_date' => today()->startOfDay()->toDateTimeString(),
        ]);
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_daily_rollup_command_processes_each_active_organization_independently(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $firstEmployee = Employee::factory()->forOrganization($first)->create();
        $secondEmployee = Employee::factory()->forOrganization($second)->create();

        ActivityLog::factory()->forOrganization($first)->create([
            'employee_id' => $firstEmployee->id,
            'work_date' => today()->toDateString(),
        ]);
        ActivityLog::factory()->forOrganization($second)->create([
            'employee_id' => $secondEmployee->id,
            'work_date' => today()->toDateString(),
        ]);

        $this->artisan('treck:daily-rollup')
            ->expectsOutput('Daily rollup complete for '.today()->toDateString().' across 2 organization(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('attendance', ['organization_id' => $first->id, 'employee_id' => $firstEmployee->id]);
        $this->assertDatabaseHas('attendance', ['organization_id' => $second->id, 'employee_id' => $secondEmployee->id]);
    }

    public function test_screenshot_uploads_use_organization_scoped_storage_paths(): void
    {
        Storage::fake('local');
        [$organization, , , $token] = $this->ownedAgentDevice();

        $this->withToken($token)->post('/api/agent/screenshots', [
            'image' => UploadedFile::fake()->image('shot.jpg'),
            'captured_at' => Carbon::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
            'monitor_number' => 0,
        ], ['Accept' => 'application/json'])->assertCreated();

        $shot = Screenshot::firstOrFail();
        $this->assertStringStartsWith("organizations/{$organization->id}/screenshots/{$shot->computer_id}/2026-09-02/", $shot->path);
        Storage::disk('local')->assertExists($shot->path);
    }

    public function test_storage_migration_copies_legacy_screenshots_without_deleting_source(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        $hash = hash('sha256', 'legacy-image');
        $legacyPath = "screenshots/{$computer->id}/2026-09-02/{$hash}.jpg";
        Storage::disk('local')->put($legacyPath, 'legacy-bytes');

        $shot = Screenshot::factory()->forComputer($computer)->create([
            'captured_at' => Carbon::parse('2026-09-02 10:00:00', 'UTC'),
            'filename' => "{$hash}.jpg",
            'image_hash' => $hash,
            'path' => $legacyPath,
        ]);
        $tenantPath = "organizations/{$organization->id}/screenshots/{$computer->id}/2026-09-02/{$hash}.jpg";

        $this->artisan('treck:migrate-tenant-storage', [
            '--organization' => (string) $organization->id,
            '--dry-run' => true,
        ])->expectsOutput('planned=1')->assertSuccessful();
        Storage::disk('local')->assertMissing($tenantPath);

        $this->artisan('treck:migrate-tenant-storage', [
            '--organization' => (string) $organization->id,
        ])->expectsOutput('copied=1')->assertSuccessful();

        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($tenantPath);
        $this->assertSame($tenantPath, $shot->fresh()->path);

        $this->artisan('treck:migrate-tenant-storage', [
            '--organization' => (string) $organization->id,
            '--verify' => true,
        ])->expectsOutput('planned=0')->assertSuccessful();
    }

    public function test_readiness_check_is_read_only_and_does_not_assign_platform_super_admin(): void
    {
        $beforeRoles = Role::count();

        $this->artisan('treck:verify-saas-readiness')
            ->expectsOutput('platform_super_admin_assignments=0')
            ->assertSuccessful();

        $this->assertSame($beforeRoles, Role::count());
        $this->assertFalse(Role::query()->where('name', 'platform-super-admin')->exists());
    }
}
