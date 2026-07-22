<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Jobs\EvaluateNotificationsJob;
use App\Listeners\EvaluatePresenceNotifications;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9 — event-driven wiring into completed phases (without modifying them):
 * the ApplicationUsage observer queues evaluation, the PresenceChanged listener
 * feeds the engine, and EvaluateNotificationsJob rehydrates context + generates
 * notifications. Confirms notification work stays off the ingest path (async).
 */
class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private User $admin;

    private function computer(): Computer
    {
        return Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
        ]);
    }

    public function test_application_usage_creation_queues_an_evaluation_job(): void
    {
        Bus::fake();
        $computer = $this->computer();

        ApplicationUsage::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'application_name' => 'Steam',
            'session_id' => 'sess-1',
        ]);

        Bus::assertDispatched(EvaluateNotificationsJob::class, fn ($job) => $job->source === 'app_usage'
            && $job->computerId === $computer->id
            && $job->data['application_name'] === 'Steam');
    }

    public function test_application_usage_without_session_id_does_not_queue(): void
    {
        Bus::fake();
        $computer = $this->computer();

        ApplicationUsage::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'session_id' => null,
        ]);

        Bus::assertNotDispatched(EvaluateNotificationsJob::class);
    }

    public function test_evaluate_job_generates_notifications_from_scalar_payload(): void
    {
        Queue::fake();
        $computer = $this->computer();
        NotificationRule::where('event_type', NotificationEventType::AppBlacklisted->value)
            ->update(['config' => ['processes' => ['mimikatz']]]);

        (new EvaluateNotificationsJob(
            source: 'app_usage',
            computerId: $computer->id,
            employeeId: $computer->employee_id,
            data: ['application_name' => 'mimikatz', 'executable' => 'mimikatz.exe', 'duration_seconds' => 3],
        ))->handle(app(NotificationEngine::class));

        $this->assertTrue(
            NotificationLog::where('event_type', NotificationEventType::AppBlacklisted->value)->exists()
        );
    }

    public function test_presence_listener_feeds_the_engine(): void
    {
        Queue::fake();
        $computer = $this->computer();
        $presence = ComputerPresence::factory()->create([
            'computer_id' => $computer->id,
            'status' => PresenceStatus::Locked,
            'idle_seconds' => 0,
        ]);

        app(EvaluatePresenceNotifications::class)->handle(new PresenceChanged($presence));

        $this->assertTrue(
            NotificationLog::where('event_type', NotificationEventType::PresenceLocked->value)->exists()
        );
    }
}
