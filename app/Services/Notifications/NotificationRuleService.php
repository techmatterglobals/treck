<?php

namespace App\Services\Notifications;

use App\Models\NotificationRule;

/**
 * Loads the configured notification rules into a {@see RuleSet} keyed by event
 * type (Phase 9). Kept tiny and side-effect-free so the engine can call it once
 * per dispatch; the DB is the source of truth so admins can retune rules live.
 */
class NotificationRuleService
{
    public function ruleSet(): RuleSet
    {
        return new RuleSet(NotificationRule::all()->keyBy('event_type'));
    }
}
