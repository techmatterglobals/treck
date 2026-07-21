using Treck.Agent.Offline;

namespace Treck.Agent.Spooling;

/// <summary>
/// Destination the interactive helper writes events to (screenshots, app-usage
/// sessions, heartbeats). Each event becomes a spool sidecar the Session-0
/// service ingests into the offline queue, keeping the service the single writer
/// of the SQLite database (no cross-process contention).
/// </summary>
public interface IAgentEventSpool
{
    void Submit(OfflineEvent evt);
}
