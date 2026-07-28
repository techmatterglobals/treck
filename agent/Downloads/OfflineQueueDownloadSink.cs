using Treck.Agent.Offline;

namespace Treck.Agent.Downloads;

/// <summary>
/// Enqueues a download event straight into the offline SQLite queue. Used when
/// collection and sync share a process (console / development mode), so local
/// runs record downloads without a separate helper.
/// </summary>
public sealed class OfflineQueueDownloadSink : IDownloadSink
{
    private readonly IOfflineEventStore _eventStore;

    public OfflineQueueDownloadSink(IOfflineEventStore eventStore) => _eventStore = eventStore;

    public void Submit(OfflineEvent evt) => _eventStore.Enqueue(evt);
}
