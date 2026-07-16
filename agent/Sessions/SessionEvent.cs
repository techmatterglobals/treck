namespace Treck.Agent.Sessions;

/// <summary>An observed Windows session transition (UTC-timestamped).</summary>
public sealed record SessionEvent(SessionEventType Type, DateTimeOffset TimestampUtc);
