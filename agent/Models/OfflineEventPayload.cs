namespace Treck.Agent.Models;

/// <summary>
/// Wire shape for a queued event upload, matching the server-side
/// StoreAgentEventRequest. Serialized snake_case (idempotency_key, created_at,
/// …) and POSTed to <c>/api/agent/events</c> (delivered in M6). The server
/// stores the event transactionally and acknowledges before the agent drops it.
/// </summary>
public sealed record OfflineEventPayload(
    string Kind,
    string IdempotencyKey,
    DateTimeOffset CreatedAt,
    string Payload);
