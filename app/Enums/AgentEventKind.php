<?php

namespace App\Enums;

/**
 * The kinds of events the desktop agent drains from its offline queue into
 * `agent_events` (M6). Deliberately narrow: only the telemetry the agent
 * currently produces — periodic activity heartbeats and Windows session
 * transitions. Screenshots / application-usage are out of scope for M6.
 */
enum AgentEventKind: string
{
    case Heartbeat = 'heartbeat';
    case Session = 'session';

    /** All values — handy for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
