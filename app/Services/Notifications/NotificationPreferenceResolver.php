<?php

namespace App\Services\Notifications;

use App\Enums\NotificationSeverity;
use App\Enums\UserRole;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\Channels\EmailChannel;

/**
 * Resolves who receives a notification and on which channels (Phase 9).
 *
 * Recipients are active administrators. Per-user preferences (a missing row =
 * defaults) filter by minimum severity and enabled channels, and enforce quiet
 * hours + digest mode: outside "immediate" eligibility the email channel is
 * suppressed for non-critical alerts (the in-app inbox item is still recorded).
 * Critical always delivers on every rule-enabled channel.
 */
class NotificationPreferenceResolver
{
    /**
     * @param  list<string>  $ruleChannels  channels the rule is configured to use
     * @return list<array{user:User,channels:list<string>}>
     */
    public function recipients(NotificationSeverity $severity, array $ruleChannels): array
    {
        $admins = User::query()->active()->withRole(UserRole::Admin->value)->get();
        $prefs = NotificationPreference::whereIn('user_id', $admins->pluck('id'))->get()->keyBy('user_id');

        $recipients = [];

        foreach ($admins as $admin) {
            /** @var NotificationPreference|null $pref */
            $pref = $prefs->get($admin->id);

            // Minimum-severity filter.
            $minSeverity = $pref?->minSeverity() ?? NotificationSeverity::Info;
            if (! $severity->atLeast($minSeverity)) {
                continue;
            }

            // Channel intersection (no pref row = all rule channels).
            $channels = $pref
                ? array_values(array_filter($ruleChannels, fn (string $c) => $pref->allowsChannel($c)))
                : $ruleChannels;

            // Quiet hours / digest suppress immediate email for non-critical.
            $suppressEmail = $severity !== NotificationSeverity::Critical
                && $pref !== null
                && ($pref->digest || $pref->inQuietHours());

            if ($suppressEmail) {
                $channels = array_values(array_filter($channels, fn (string $c) => $c !== EmailChannel::KEY));
            }

            if ($channels === []) {
                continue;
            }

            $recipients[] = ['user' => $admin, 'channels' => $channels];
        }

        return $recipients;
    }
}
