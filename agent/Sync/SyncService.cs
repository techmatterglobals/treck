using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Offline;

namespace Treck.Agent.Sync;

public sealed class SyncService : ISyncService
{
    private readonly IOfflineEventStore _store;
    private readonly IEventUploader _uploader;
    private readonly OfflineStoreOptions _options;
    private readonly ILogger<SyncService> _logger;

    public SyncService(
        IOfflineEventStore store,
        IEventUploader uploader,
        IOptions<OfflineStoreOptions> options,
        ILogger<SyncService> logger)
    {
        _store = store;
        _uploader = uploader;
        _options = options.Value;
        _logger = logger;
    }

    public async Task<SyncResult> SyncPendingAsync(CancellationToken cancellationToken)
    {
        var pending = _store.GetPending(_options.BatchSize);
        if (pending.Count == 0)
        {
            return new SyncResult(0, 0, 0);
        }

        var acknowledged = new List<long>();

        foreach (var offlineEvent in pending)
        {
            cancellationToken.ThrowIfCancellationRequested();

            bool uploaded;
            try
            {
                uploaded = await _uploader.TryUploadAsync(offlineEvent, cancellationToken);
            }
            catch (Exception ex)
            {
                // Keep this and all following events (preserve order); retry later.
                _store.RecordFailure(new[] { offlineEvent.Id }, ex.Message);
                _logger.LogWarning(ex, "Upload threw for event {Id}; stopping this cycle.", offlineEvent.Id);
                break;
            }

            if (uploaded)
            {
                acknowledged.Add(offlineEvent.Id);
            }
            else
            {
                // Stop on first non-ack so ordering is preserved.
                _store.RecordFailure(new[] { offlineEvent.Id }, "server did not acknowledge");
                break;
            }
        }

        if (acknowledged.Count > 0)
        {
            _store.MarkSynced(acknowledged);
        }

        _store.Prune();

        var remaining = _store.CountPending();
        var failed = pending.Count - acknowledged.Count;

        if (acknowledged.Count > 0 || failed > 0)
        {
            _logger.LogInformation(
                "Sync cycle: uploaded={Uploaded} failed={Failed} pending={Pending}",
                acknowledged.Count, failed, remaining);
        }

        return new SyncResult(acknowledged.Count, failed, remaining);
    }
}
