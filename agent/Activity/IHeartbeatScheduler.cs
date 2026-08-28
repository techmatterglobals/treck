namespace Treck.Agent.Activity;

/// <summary>
/// Produces a <see cref="HeartbeatEvent"/> on a fixed interval (default 60s) and
/// publishes it internally via <see cref="HeartbeatProduced"/>. No API calls.
/// </summary>
public interface IHeartbeatScheduler : IDisposable
{
    event EventHandler<HeartbeatEvent>? HeartbeatProduced;

    void Start();

    void Stop();
}
