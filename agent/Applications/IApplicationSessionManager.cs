namespace Treck.Agent.Applications;

/// <summary>
/// Turns a stream of foreground snapshots into completed usage <em>sessions</em>.
/// A session spans one contiguous period on the same process + window title; a
/// change ends the open session (raising <see cref="SessionCompleted"/>) and
/// opens the next. Only completed sessions are ever emitted.
/// </summary>
public interface IApplicationSessionManager
{
    /// <summary>Raised once per completed session, ready to enqueue.</summary>
    event EventHandler<ApplicationUsageEvent>? SessionCompleted;

    /// <summary>
    /// Observe the current foreground application (null = none/locked/ignored).
    /// Closes the open session and/or opens a new one as required.
    /// </summary>
    void Track(ApplicationInfo? current, DateTimeOffset nowUtc);

    /// <summary>Close any open session now (on lock, logoff or shutdown/stop).</summary>
    void Flush(DateTimeOffset nowUtc);
}
