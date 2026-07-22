<?php

namespace App\Services\Notifications\Rules;

use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;

/**
 * A notification rule (Phase 9). Given a source context, it decides which
 * notifications (if any) should be raised, returning drafts. Rules contain only
 * the "what happened" logic + threshold checks (reading config from the RuleSet);
 * the engine owns severity/channel/throttle/recipient decisions.
 */
interface NotificationRuleContract
{
    /** Does this rule handle the context's source? */
    public function supports(NotificationContext $context): bool;

    /**
     * Produce zero or more drafts for the context.
     *
     * @return iterable<NotificationDraft>
     */
    public function evaluate(NotificationContext $context, RuleSet $rules): iterable;
}
