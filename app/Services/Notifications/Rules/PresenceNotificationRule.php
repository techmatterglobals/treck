<?php

namespace App\Services\Notifications\Rules;

use App\Enums\NotificationEventType;
use App\Enums\PresenceStatus;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;

/**
 * Presence-based rules (Phase 9): online / offline / idle-beyond-threshold /
 * locked / logged-out / reconnected-after-extended-offline. Fed by the existing
 * PresenceChanged event, so nothing in the presence pipeline changes.
 */
class PresenceNotificationRule implements NotificationRuleContract
{
    public function supports(NotificationContext $context): bool
    {
        return $context->source === 'presence';
    }

    public function evaluate(NotificationContext $context, RuleSet $rules): iterable
    {
        $status = $context->get('status');
        if (! $status instanceof PresenceStatus) {
            return [];
        }

        $previous = $context->get('previous_status');
        $who = $this->subject($context);

        return match ($status) {
            PresenceStatus::Active => $this->onActive($context, $rules, $previous, $who),
            PresenceStatus::Idle => $this->maybeIdle($context, $rules, $who),
            PresenceStatus::Locked => $this->one(NotificationEventType::PresenceLocked, $context, 'Workstation locked', "{$who} locked the workstation."),
            PresenceStatus::LoggedOut => $this->one(NotificationEventType::PresenceLoggedOut, $context, 'Employee logged out', "{$who} logged out."),
            PresenceStatus::Offline => $this->one(NotificationEventType::PresenceOffline, $context, 'Employee offline', "{$who} went offline."),
        };
    }

    /** @return iterable<NotificationDraft> */
    private function onActive(NotificationContext $context, RuleSet $rules, mixed $previous, string $who): iterable
    {
        $wasOffline = $previous instanceof PresenceStatus
            && in_array($previous, [PresenceStatus::Offline, PresenceStatus::LoggedOut], true);

        $offlineFor = (int) $context->get('offline_duration_seconds', 0);
        $reconnectThreshold = (int) $rules->config(NotificationEventType::PresenceReconnected, 'offline_threshold_seconds', 3600);

        if ($wasOffline && $offlineFor >= $reconnectThreshold && $reconnectThreshold > 0) {
            yield from $this->one(
                NotificationEventType::PresenceReconnected, $context,
                'Device reconnected', "{$who} reconnected after ".$this->humanDuration($offlineFor).' offline.'
            );

            return;
        }

        yield from $this->one(NotificationEventType::PresenceOnline, $context, 'Employee online', "{$who} came online.");
    }

    /** @return iterable<NotificationDraft> */
    private function maybeIdle(NotificationContext $context, RuleSet $rules, string $who): iterable
    {
        $idle = (int) $context->get('idle_seconds', 0);
        $threshold = (int) $rules->config(NotificationEventType::PresenceIdle, 'idle_threshold_seconds', 900);

        if ($threshold > 0 && $idle >= $threshold) {
            yield from $this->one(
                NotificationEventType::PresenceIdle, $context,
                'Employee idle', "{$who} has been idle for ".$this->humanDuration($idle).'.'
            );
        }
    }

    /** @return iterable<NotificationDraft> */
    private function one(NotificationEventType $type, NotificationContext $context, string $title, string $message): iterable
    {
        yield new NotificationDraft(
            type: $type,
            title: $title,
            message: $message,
            dedupeKey: $type->value.':'.($context->computer?->id ?? '0'),
            computer: $context->computer,
            employee: $context->employee,
            metadata: ['status' => $context->get('status')?->value],
        );
    }

    private function subject(NotificationContext $context): string
    {
        $name = $context->employee?->name ?? 'An employee';
        $host = $context->computer?->hostname;

        return $host ? "{$name} ({$host})" : $name;
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        return $minutes < 60 ? "{$minutes}m" : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
