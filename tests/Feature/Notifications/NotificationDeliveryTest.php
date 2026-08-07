<?php

namespace Tests\Feature\Notifications;

use App\Events\NotificationCreated;
use App\Jobs\SendNotificationJob;
use App\Mail\NotificationMail;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 9 — channel delivery via SendNotificationJob: in-app marks delivered +
 * broadcasts; email queues an HTML mailable; unknown/failed channels are marked.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function log(array $attrs = []): NotificationLog
    {
        $user = User::factory()->create();

        return NotificationLog::factory()->create(array_merge([
            'recipient_id' => $user->id,
            'status' => 'pending',
            'delivered_at' => null,
        ], $attrs));
    }

    public function test_in_app_delivery_marks_delivered_and_broadcasts(): void
    {
        Event::fake([NotificationCreated::class]);
        $log = $this->log(['channel' => 'in_app']);

        (new SendNotificationJob($log->id))->handle(app(NotificationDeliveryService::class));

        $this->assertSame('delivered', $log->refresh()->status);
        $this->assertNotNull($log->delivered_at);
        Event::assertDispatched(NotificationCreated::class, fn ($e) => $e->log->id === $log->id);
    }

    public function test_email_delivery_queues_mailable_to_recipient(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $log = NotificationLog::factory()->create([
            'recipient_id' => $user->id,
            'channel' => 'email',
            'status' => 'pending',
            'delivered_at' => null,
        ]);

        (new SendNotificationJob($log->id))->handle(app(NotificationDeliveryService::class));

        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('admin@example.test') && $m->log->id === $log->id);
        $this->assertSame('delivered', $log->refresh()->status);
    }

    public function test_already_delivered_notification_is_not_redelivered(): void
    {
        Event::fake([NotificationCreated::class]);
        $log = $this->log(['channel' => 'in_app', 'status' => 'delivered', 'delivered_at' => now()]);

        (new SendNotificationJob($log->id))->handle(app(NotificationDeliveryService::class));

        Event::assertNotDispatched(NotificationCreated::class);
    }
}
