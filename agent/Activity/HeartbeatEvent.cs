namespace Treck.Agent.Activity;

/// <summary>
/// Internal, per-interval activity sample. Not sent anywhere in M4 — it is the
/// model that later milestones will map to an API payload.
/// </summary>
/// <param name="TimestampUtc">When the sample was taken.</param>
/// <param name="ElapsedSeconds">Length of the interval this sample covers.</param>
/// <param name="IdleTimeSeconds">Observed idle time (since last input) at capture.</param>
/// <param name="IsIdle">True when idle time met/exceeded the configured threshold.</param>
/// <param name="ActiveSeconds">Portion of the interval counted as active.</param>
/// <param name="IdleSeconds">Portion of the interval counted as idle.</param>
public sealed record HeartbeatEvent(
    DateTimeOffset TimestampUtc,
    int ElapsedSeconds,
    int IdleTimeSeconds,
    bool IsIdle,
    int ActiveSeconds,
    int IdleSeconds);
