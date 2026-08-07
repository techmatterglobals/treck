<?php

namespace App\Services\Notifications\Rules;

use App\Enums\NotificationEventType;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;

/**
 * Windows-agent + system rules (Phase 9): registration failure, heartbeats
 * stopped, synchronization failures, offline queue growing, and the system
 * "computer inactive" alert. Reported via the engine's report() API — some are
 * server-observable (heartbeat-stopped / inactive via the presence sweep), others
 * are designed for a future agent-fed signal.
 */
class AgentNotificationRule implements NotificationRuleContract
{
    public function supports(NotificationContext $context): bool
    {
        return in_array($context->source, ['agent', 'system'], true);
    }

    public function evaluate(NotificationContext $context, RuleSet $rules): iterable
    {
        $type = NotificationEventType::tryFromValue($context->source.'.'.$context->get('event'));
        if ($type === null) {
            return;
        }

        $who = $context->computer?->hostname ?? ($context->employee?->name ?? 'A device');
        $detail = trim((string) $context->get('detail', ''));
        $windowsUser = trim((string) $context->get('windows_username', ''));
        $suffix = $detail !== '' ? " ({$detail})" : '';

        // Unknown-user alerts throttle per (computer, windows account) so each
        // new shared-computer account is surfaced once, not once per computer.
        $dedupeKey = $type === NotificationEventType::SystemUnknownUser
            ? $type->value.':'.($context->computer?->id ?? '0').':'.strtolower($windowsUser)
            : $type->value.':'.($context->computer?->id ?? '0');

        yield new NotificationDraft(
            type: $type,
            title: $type->label(),
            message: $this->message($type, $who, $windowsUser).$suffix,
            dedupeKey: $dedupeKey,
            computer: $context->computer,
            employee: $context->employee,
            metadata: array_filter([
                'detail' => $detail ?: null,
                'windows_username' => $windowsUser ?: null,
                'queue_depth' => $context->get('queue_depth'),
            ], fn ($v) => $v !== null),
        );
    }

    private function message(NotificationEventType $type, string $who, string $windowsUser = ''): string
    {
        return match ($type) {
            NotificationEventType::AgentRegistrationFailed => "{$who} failed to register with the server.",
            NotificationEventType::AgentHeartbeatStopped => "{$who} stopped sending heartbeats.",
            NotificationEventType::AgentSyncFailed => "{$who} reported repeated synchronization failures.",
            NotificationEventType::AgentQueueGrowing => "{$who} has an offline queue growing beyond the threshold.",
            NotificationEventType::SystemInactive => "{$who} has been inactive beyond the configured duration.",
            NotificationEventType::SystemUnknownUser => "{$who} reported an unrecognized Windows user"
                .($windowsUser !== '' ? " '{$windowsUser}'" : '').' that is not mapped to any employee.',
            default => "{$who} raised an agent alert.",
        };
    }
}
