<?php

namespace App\Services\Notifications\Rules;

use App\Enums\NotificationEventType;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;
use Illuminate\Support\Str;

/**
 * Application-usage rules (Phase 9): restricted application opened, blacklisted
 * process detected, and application used beyond a configured duration. Fed by a
 * completed application_usage row (Phase 7).
 */
class ApplicationNotificationRule implements NotificationRuleContract
{
    public function supports(NotificationContext $context): bool
    {
        return $context->source === 'app_usage';
    }

    public function evaluate(NotificationContext $context, RuleSet $rules): iterable
    {
        $application = trim((string) $context->get('application_name', ''));
        $executable = trim((string) $context->get('executable', ''));
        $duration = (int) $context->get('duration_seconds', 0);
        $who = $this->subject($context);

        if ($application === '' && $executable === '') {
            return;
        }

        // Blacklisted process (critical).
        if ($this->matches($rules->config(NotificationEventType::AppBlacklisted, 'processes', []), $application, $executable)) {
            yield $this->draft(NotificationEventType::AppBlacklisted, $context, $application,
                'Blacklisted process detected', "{$who} ran a blacklisted process: {$this->display($application, $executable)}.");
        }

        // Restricted application opened (warning).
        if ($this->matches($rules->config(NotificationEventType::AppRestricted, 'applications', []), $application, $executable)) {
            yield $this->draft(NotificationEventType::AppRestricted, $context, $application,
                'Restricted application opened', "{$who} opened a restricted application: {$this->display($application, $executable)}.");
        }

        // Long usage (warning) — optionally scoped to a watch-list of apps.
        $maxUsage = (int) $rules->config(NotificationEventType::AppLongUsage, 'max_usage_seconds', 3600);
        $watch = (array) $rules->config(NotificationEventType::AppLongUsage, 'applications', []);
        $watched = $watch === [] || $this->matches($watch, $application, $executable);

        if ($maxUsage > 0 && $duration >= $maxUsage && $watched) {
            $minutes = intdiv($duration, 60);
            yield $this->draft(NotificationEventType::AppLongUsage, $context, $application,
                'Long application usage', "{$who} used {$this->display($application, $executable)} for {$minutes} minute(s).");
        }
    }

    /** @param array<int,string> $list */
    private function matches(mixed $list, string $application, string $executable): bool
    {
        foreach ((array) $list as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }

            if (Str::contains($application, $needle, ignoreCase: true)
                || Str::contains($executable, $needle, ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    private function draft(NotificationEventType $type, NotificationContext $context, string $application, string $title, string $message): NotificationDraft
    {
        // Per-application dedupe so different apps notify independently, but the
        // same app is throttled within the rule's window.
        return new NotificationDraft(
            type: $type,
            title: $title,
            message: $message,
            dedupeKey: $type->value.':'.($context->computer?->id ?? '0').':'.Str::lower($application),
            computer: $context->computer,
            employee: $context->employee,
            metadata: [
                'application' => $application,
                'executable' => $context->get('executable'),
                'duration_seconds' => $context->get('duration_seconds'),
            ],
        );
    }

    private function display(string $application, string $executable): string
    {
        return $application !== '' ? $application : $executable;
    }

    private function subject(NotificationContext $context): string
    {
        $name = $context->employee?->name ?? 'An employee';
        $host = $context->computer?->hostname;

        return $host ? "{$name} ({$host})" : $name;
    }
}
