<?php

namespace App\Services\Notifications\Rules;

use App\Enums\NotificationEventType;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;

/**
 * Screenshot rules (Phase 9): capture failure and synchronization failure.
 * These are reported explicitly via the engine's report() API — either by a
 * server-side detector or a future agent-fed signal — so the rule maps the
 * reported failure kind to the right event type.
 */
class ScreenshotNotificationRule implements NotificationRuleContract
{
    public function supports(NotificationContext $context): bool
    {
        return $context->source === 'screenshot';
    }

    public function evaluate(NotificationContext $context, RuleSet $rules): iterable
    {
        $type = match ((string) $context->get('event')) {
            'failed' => NotificationEventType::ScreenshotFailed,
            'sync_failed' => NotificationEventType::ScreenshotSyncFailed,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $who = $context->computer?->hostname ?? ($context->employee?->name ?? 'A device');
        $reason = trim((string) $context->get('reason', ''));
        $suffix = $reason !== '' ? " ({$reason})" : '';

        yield new NotificationDraft(
            type: $type,
            title: $type === NotificationEventType::ScreenshotFailed ? 'Screenshot capture failed' : 'Screenshot sync failed',
            message: $type === NotificationEventType::ScreenshotFailed
                ? "{$who} failed to capture a screenshot{$suffix}."
                : "{$who} failed to synchronize a screenshot{$suffix}.",
            dedupeKey: $type->value.':'.($context->computer?->id ?? '0'),
            computer: $context->computer,
            employee: $context->employee,
            metadata: ['reason' => $reason],
        );
    }
}
