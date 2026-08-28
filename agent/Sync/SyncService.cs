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
        var dropped = 0;

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
                // Transient (network/auth): keep this and all following events
                // (preserve order) and retry later.
                _store.RecordFailure(new[] { offlineEvent.Id }, ex.Message);
                _logger.LogWarning(ex, "Upload threw for event {Id}; stopping this cycle.", offlineEvent.Id);
                break;
            }

            if (uploaded)
            {
                acknowledged.Add(offlineEvent.Id);
                continue;
            }

            // The server rejected the event (e.g. a permanent 4xx). Record the
            // attempt; if it has now been rejected too many times, DROP it so a
            // single poison item cannot wedge the whole ordered queue forever
            // (every newer event behind it would otherwise never ship).
            _store.RecordFailure(new[] { offlineEvent.Id }, "server did not acknowledge");

            if (offlineEvent.Attempts + 1 >= _options.MaxUploadAttempts)
            {
                _store.Drop(offlineEvent.Id);
                dropped++;
                _logger.LogError(
                    "Dropping event {Id} ({Kind}) after {Attempts} rejected upload attempts; "
                    + "queue advancing so newer events can ship.",
                    offlineEvent.Id, offlineEvent.Kind, offlineEvent.Attempts + 1);

                continue; // skip the poison, keep draining the rest of the batch
            }

            // Not yet at the cap: stop on first non-ack so ordering is preserved.
            break;
        }

        if (acknowledged.Count > 0)
        {
            _store.MarkSynced(acknowledged);
        }

        _store.Prune();

        var remaining = _store.CountPending();
        var failed = pending.Count - acknowledged.Count - dropped;

        if (acknowledged.Count > 0 || failed > 0 || dropped > 0)
        {
            _logger.LogInformation(
                "Sync cycle: uploaded={Uploaded} failed={Failed} dropped={Dropped} pending={Pending}",
                acknowledged.Count, failed, dropped, remaining);
        }

        return new SyncResult(acknowledged.Count, failed, remaining);
    }
}
