namespace Treck.Agent.Sessions;

/// <summary>
/// Detects Windows session transitions and publishes them internally via
/// <see cref="SessionChanged"/>. Event-driven — no polling.
/// </summary>
public interface ISessionMonitor : IDisposable
{
    /// <summary>Raised (on a background thread) for each de-duplicated session event.</summary>
    event EventHandler<SessionEvent>? SessionChanged;

    void Start();

    void Stop();
}
