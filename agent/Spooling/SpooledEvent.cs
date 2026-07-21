using Treck.Agent.Offline;

namespace Treck.Agent.Spooling;

/// <summary>
/// Wire shape of one spool sidecar: the minimal fields needed to reconstruct an
/// <see cref="OfflineEvent"/> on the service side. Preserving the idempotency key
/// keeps the queue's dedup guarantee across the process handoff.
/// </summary>
public sealed record SpooledEvent(
    string Kind,
    string IdempotencyKey,
    string PayloadJson,
    DateTimeOffset CreatedAtUtc)
{
    public static SpooledEvent From(OfflineEvent evt) => new(
        Kind: evt.Kind.ToString(),
        IdempotencyKey: evt.IdempotencyKey,
        PayloadJson: evt.PayloadJson,
        CreatedAtUtc: evt.CreatedAtUtc);

    /// <summary>Reconstruct an OfflineEvent, or null if the kind is unrecognized.</summary>
    public OfflineEvent? ToOfflineEvent()
    {
        if (!Enum.TryParse<OfflineEventKind>(Kind, out var kind))
        {
            return null;
        }

        return new OfflineEvent
        {
            IdempotencyKey = IdempotencyKey,
            Kind = kind,
            PayloadJson = PayloadJson,
            CreatedAtUtc = CreatedAtUtc,
        };
    }
}
