namespace Treck.Agent.Activity;

/// <summary>
/// Pure classification of one interval into active vs. idle, given the observed
/// idle time and the configured threshold. No I/O — fully unit-testable.
/// </summary>
public static class HeartbeatCalculator
{
    public static HeartbeatEvent Create(
        DateTimeOffset now,
        TimeSpan elapsed,
        TimeSpan idleTime,
        TimeSpan idleThreshold)
    {
        var elapsedSeconds = Math.Max(0, (int)Math.Round(elapsed.TotalSeconds));
        var idleTimeSeconds = Math.Max(0, (int)Math.Round(idleTime.TotalSeconds));
        var isIdle = idleTime >= idleThreshold;

        return new HeartbeatEvent(
            TimestampUtc: now,
            ElapsedSeconds: elapsedSeconds,
            IdleTimeSeconds: idleTimeSeconds,
            IsIdle: isIdle,
            ActiveSeconds: isIdle ? 0 : elapsedSeconds,
            IdleSeconds: isIdle ? elapsedSeconds : 0);
    }
}
