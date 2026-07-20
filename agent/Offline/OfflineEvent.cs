namespace Treck.Agent.Offline;

public enum OfflineEventKind
{
    Heartbeat,
    Session,
    AppUsage,
    Screenshot,
}

/// <summary>
/// A queued, not-yet-acknowledged event. The payload is opaque JSON so the store
/// stays agnostic of what it carries (heartbeat, session, …).
/// </summary>
public sealed class OfflineEvent
{
    public long Id { get; init; }

    public string IdempotencyKey { get; init; } = string.Empty;

    public OfflineEventKind Kind { get; init; }

    public string PayloadJson { get; init; } = string.Empty;

    public DateTimeOffset CreatedAtUtc { get; init; }

    public DateTimeOffset? SyncedAtUtc { get; init; }

    public int Attempts { get; init; }

    /// <summary>Creates a new event with a unique idempotency key (dedup on enqueue/upload).</summary>
    public static OfflineEvent Create(OfflineEventKind kind, string payloadJson, DateTimeOffset createdAtUtc)
        => new()
        {
            IdempotencyKey = Guid.NewGuid().ToString("N"),
            Kind = kind,
            PayloadJson = payloadJson,
            CreatedAtUtc = createdAtUtc,
        };
}
