namespace Treck.Agent.Models;

/// <summary>
/// Wire shape for a queued event upload. Serialized snake_case
/// (idempotency_key, created_at, …). The server side is delivered in M6; until
/// then uploads simply fail and events remain safely queued.
/// </summary>
public sealed record OfflineEventPayload(
    string Kind,
    string IdempotencyKey,
    DateTimeOffset CreatedAt,
    string Payload);
