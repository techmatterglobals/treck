<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Services\Notifications\Rules\AgentNotificationRule;
use App\Services\Notifications\Rules\ApplicationNotificationRule;
use App\Services\Notifications\Rules\DownloadNotificationRule;
use App\Services\Notifications\Rules\NotificationRuleContract;
use App\Services\Notifications\Rules\PresenceNotificationRule;
use App\Services\Notifications\Rules\ScreenshotNotificationRule;
use App\Services\Tenancy\MonitoringTenantOwnership;

/**
 * The centralized Notification Engine (Phase 9). Receives a source signal,
 * evaluates the configured rules, prevents duplicate/flooding alerts (throttle
 * by dedupe key + window), persists one history row per recipient+channel, and
 * queues asynchronous delivery. It never delivers inline — delivery is done by
 * SendNotificationJob on the queue, so it never blocks the caller (agent sync,
 * presence, app tracking, screenshots).
 */
class NotificationEngine
{
    /** @var list<NotificationRuleContract> */
    private array $rules;

    public function __construct(
        private readonly NotificationRuleService $ruleService,
        private readonly NotificationPreferenceResolver $recipients,
        private readonly MonitoringTenantOwnership $ownership,
        PresenceNotificationRule $presence,
        ApplicationNotificationRule $application,
        ScreenshotNotificationRule $screenshot,
        AgentNotificationRule $agent,
        DownloadNotificationRule $download,
    ) {
        $this->rules = [$presence, $application, $screenshot, $agent, $download];
    }

    /**
     * Evaluate a context and queue any resulting notifications.
     *
     * @return int number of notification rows created (across recipients/channels)
     */
    public function dispatch(NotificationContext $context): int
    {
        $ruleSet = $this->ruleService->ruleSet();
        $created = 0;

        foreach ($this->rules as $rule) {
            if (! $rule->supports($context)) {
                continue;
            }

            foreach ($rule->evaluate($context, $ruleSet) as $draft) {
                $created += $this->process($draft, $ruleSet);
            }
        }

        return $created;
    }

    /**
     * Convenience entry point for explicitly-reported events (screenshot / agent
     * / system failures) that are not carried by an existing domain event.
     *
     * @param  array<string,mixed>  $data
     */
    public function report(string $source, string $event, ?Computer $computer = null, ?Employee $employee = null, array $data = []): int
    {
        return $this->dispatch(new NotificationContext(
            source: $source,
            data: ['event' => $event] + $data,
            computer: $computer,
            employee: $employee ?? $computer?->employee,
            organizationId: $computer !== null || $employee !== null
                ? $this->ownership->resolve($computer, $employee ?? $computer?->employee, true)->organizationId
                : null,
        ));
    }

    private function process(NotificationDraft $draft, RuleSet $ruleSet): int
    {
        $rule = $ruleSet->rule($draft->type);

        if ($rule === null || ! $rule->enabled) {
            return 0;
        }

        // Flood prevention: skip if the same alert fired within the throttle window.
        if ($this->throttled($draft, (int) $rule->throttle_seconds)) {
            return 0;
        }

        $severity = $rule->severity_enum;
        $channels = (array) $rule->channels;
        $created = 0;

        $organizationId = $this->ownership->resolve($draft->computer, $draft->employee, true)->organizationId;

        foreach ($this->recipients->recipients($severity, $channels, $organizationId) as $recipient) {
            foreach ($recipient['channels'] as $channel) {
                $log = NotificationLog::create([
                    'organization_id' => $organizationId,
                    'recipient_id' => $recipient['user']->id,
                    'computer_id' => $draft->computer?->id,
                    'employee_id' => $draft->employee?->id,
                    'event_type' => $draft->type->value,
                    'severity' => $severity->value,
                    'title' => $draft->title,
                    'message' => $draft->message,
                    'channel' => $channel,
                    'dedupe_key' => $draft->dedupeKey,
                    'status' => 'pending',
                    'metadata' => $draft->metadata,
                ]);

                SendNotificationJob::dispatch($log->id, $organizationId);
                $created++;
            }
        }

        return $created;
    }

    private function throttled(NotificationDraft $draft, int $throttleSeconds): bool
    {
        if ($throttleSeconds <= 0) {
            return false;
        }

        $organizationId = $this->ownership->resolve($draft->computer, $draft->employee, true)->organizationId;

        return NotificationLog::where('dedupe_key', $draft->dedupeKey)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', now()->subSeconds($throttleSeconds))
            ->exists();
    }
}
