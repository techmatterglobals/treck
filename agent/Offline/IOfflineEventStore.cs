namespace Treck.Agent.Offline;

/// <summary>
/// Durable, ordered queue of unsent events. Pure persistence — it knows nothing
/// about the API. Thread-safe.
/// </summary>
public interface IOfflineEventStore
{
    /// <summary>Create the schema if needed (idempotent).</summary>
    void Initialize();

    /// <summary>Persist an event. Duplicate idempotency keys are ignored.</summary>
    long Enqueue(OfflineEvent offlineEvent);

    /// <summary>Oldest-first batch of not-yet-synced events.</summary>
    IReadOnlyList<OfflineEvent> GetPending(int limit);

    /// <summary>Mark events acknowledged by the server (sets synced_at).</summary>
    void MarkSynced(IEnumerable<long> ids);

    /// <summary>Record a failed upload attempt (increments attempts, stores the error).</summary>
    void RecordFailure(IEnumerable<long> ids, string error);

    /// <summary>Permanently remove one event — a dead-lettered poison item.</summary>
    void Drop(long id);

    int CountPending();

    /// <summary>Delete old synced rows and enforce the max-size cap. Returns rows removed.</summary>
    int Prune();
}
