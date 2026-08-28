using Treck.Agent.Offline;
using Treck.Agent.Spooling;

namespace Treck.Agent.Downloads;

/// <summary>
/// Writes a download event to the spool (interactive helper). The Session-0
/// service ingests the sidecar into the offline queue, keeping the service the
/// single writer of the SQLite database.
/// </summary>
public sealed class SpoolDownloadSink : IDownloadSink
{
    private readonly IAgentEventSpool _spool;

    public SpoolDownloadSink(IAgentEventSpool spool) => _spool = spool;

    public void Submit(OfflineEvent evt) => _spool.Submit(evt);
}
