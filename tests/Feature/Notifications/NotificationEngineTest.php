<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use App\Enums\PresenceStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9 — rule evaluation, notification generation, throttling, severity,
 * channels and preferences. Delivery is faked (Queue) — these assert the
 * persisted notification rows the engine produces.
 */
class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('admin', 'web');
        $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private function computer(): Computer
    {
        return Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'hostname' => 'PC-1',
        ]);
    }

    private function engine(): NotificationEngine
    {
        return app(NotificationEngine::class);
    }

    public function test_idle_beyond_threshold_notifies_the_admin(): void
    {
        $computer = $this->computer();

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Idle, 'idle_seconds' => 1200],
            computer: $computer,
            employee: $computer->employee,
        ));

        $log = NotificationLog::where('event_type', NotificationEventType::PresenceIdle->value)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->recipient_id);
        $this->assertSame('in_app', $log->channel);
        $this->assertSame('warning', $log->severity);
    }

    public function test_idle_below_threshold_does_not_notify(): void
    {
        $computer = $this->computer();

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Idle, 'idle_seconds' => 60],
            computer: $computer,
        ));

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_online_is_disabled_by_default(): void
    {
        $computer = $this->computer();

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Active],
            computer: $computer,
        ));

        $this->assertSame(0, NotificationLog::where('event_type', NotificationEventType::PresenceOnline->value)->count());
    }

    public function test_throttle_prevents_duplicate_alerts_within_window(): void
    {
        $computer = $this->computer();
        $ctx = fn () => new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Locked],
            computer: $computer,
        );

        $this->engine()->dispatch($ctx());
        $this->engine()->dispatch($ctx()); // within throttle window → suppressed

        $this->assertSame(1, NotificationLog::where('event_type', NotificationEventType::PresenceLocked->value)->count());
    }

    public function test_restricted_application_notifies(): void
    {
        $computer = $this->computer();
        NotificationRule::where('event_type', NotificationEventType::AppRestricted->value)
            ->update(['config' => ['applications' => ['Steam']]]);

        $this->engine()->dispatch(new NotificationContext(
            source: 'app_usage',
            data: ['application_name' => 'Steam', 'executable' => 'steam.exe', 'duration_seconds' => 120],
            computer: $computer,
        ));

        $this->assertSame(1, NotificationLog::where('event_type', NotificationEventType::AppRestricted->value)->count());
    }

    public function test_blacklisted_process_is_critical_on_email_and_in_app(): void
    {
        $computer = $this->computer();
        NotificationRule::where('event_type', NotificationEventType::AppBlacklisted->value)
            ->update(['config' => ['processes' => ['mimikatz']]]);

        $this->engine()->dispatch(new NotificationContext(
            source: 'app_usage',
            data: ['application_name' => 'mimikatz', 'executable' => 'mimikatz.exe', 'duration_seconds' => 5],
            computer: $computer,
        ));

        $logs = NotificationLog::where('event_type', NotificationEventType::AppBlacklisted->value)->get();
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $logs->pluck('channel')->all());
        $this->assertTrue($logs->every(fn ($l) => $l->severity === 'critical'));
    }

    public function test_disabled_rule_produces_nothing(): void
    {
        $computer = $this->computer();
        NotificationRule::where('event_type', NotificationEventType::PresenceLocked->value)->update(['enabled' => false]);

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Locked],
            computer: $computer,
        ));

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_min_severity_preference_filters_out_low_severity(): void
    {
        $computer = $this->computer();
        NotificationPreference::create([
            'user_id' => $this->admin->id,
            'channels' => ['in_app', 'email'],
            'min_severity' => NotificationSeverity::Critical->value,
        ]);

        // Locked = info → below the admin's Critical floor.
        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Locked],
            computer: $computer,
        ));

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_digest_mode_suppresses_email_but_keeps_in_app(): void
    {
        $computer = $this->computer();
        // Make a warning rule use both channels.
        NotificationRule::where('event_type', NotificationEventType::PresenceIdle->value)
            ->update(['channels' => ['in_app', 'email'], 'config' => ['idle_threshold_seconds' => 600]]);
        NotificationPreference::create([
            'user_id' => $this->admin->id,
            'channels' => ['in_app', 'email'],
            'min_severity' => 'info',
            'digest' => true,
        ]);

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::Idle, 'idle_seconds' => 900],
            computer: $computer,
        ));

        $channels = NotificationLog::where('event_type', NotificationEventType::PresenceIdle->value)->pluck('channel')->all();
        $this->assertSame(['in_app'], $channels);
    }

    public function test_report_helper_raises_agent_alert(): void
    {
        $computer = $this->computer();

        $this->engine()->report('agent', 'registration_failed', $computer, data: ['detail' => 'bad key']);

        $log = NotificationLog::where('event_type', NotificationEventType::AgentRegistrationFailed->value)->first();
        $this->assertNotNull($log);
        $this->assertSame('critical', $log->severity);
    }

    public function test_delivery_job_is_queued_per_notification(): void
    {
        $computer = $this->computer();

        $this->engine()->dispatch(new NotificationContext(
            source: 'presence',
            data: ['status' => PresenceStatus::LoggedOut],
            computer: $computer,
        ));

        Queue::assertPushed(SendNotificationJob::class);
    }
}
