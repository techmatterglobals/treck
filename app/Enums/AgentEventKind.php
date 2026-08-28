<?php

namespace App\Enums;

/**
 * The kinds of events the desktop agent drains from its offline queue into
 * `agent_events`. Periodic activity heartbeats and Windows session transitions
 * (M6), completed application-usage sessions (Phase 7), and completed file
 * downloads (Phase 12 — metadata only). Screenshots remain out of scope.
 */
enum AgentEventKind: string
{
    case Heartbeat = 'heartbeat';
    case Session = 'session';
    case AppUsage = 'app_usage';
    case FileDownload = 'file_download';

    /** All values — handy for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
