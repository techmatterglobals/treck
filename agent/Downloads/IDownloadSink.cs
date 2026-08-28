using Treck.Agent.Offline;

namespace Treck.Agent.Downloads;

/// <summary>
/// Destination the <see cref="FileDownloadMonitor"/> writes a completed
/// download event to (Phase 12). Two implementations mirror the screenshot sink
/// pattern: the interactive helper spools sidecars for the Session-0 service to
/// ingest (<see cref="SpoolDownloadSink"/>); console/dev mode enqueues straight
/// into the offline queue (<see cref="OfflineQueueDownloadSink"/>).
/// </summary>
public interface IDownloadSink
{
    void Submit(OfflineEvent evt);
}
