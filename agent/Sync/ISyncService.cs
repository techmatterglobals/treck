namespace Treck.Agent.Sync;

public sealed record SyncResult(int Uploaded, int Failed, int RemainingPending);

/// <summary>
/// Orchestrates one sync pass: pull pending events (in order), upload each,
/// mark acknowledged ones synced, keep the rest. Does not touch the network or
/// the database directly — it composes <c>IOfflineEventStore</c> and
/// <c>IEventUploader</c>.
/// </summary>
public interface ISyncService
{
    Task<SyncResult> SyncPendingAsync(CancellationToken cancellationToken);
}
