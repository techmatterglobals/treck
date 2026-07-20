namespace Treck.Agent.Applications;

/// <summary>
/// Watches the interactive desktop and raises <see cref="ApplicationChanged"/>
/// the instant the foreground application or window title changes. Purely
/// event-driven (Win32 WinEvent hooks) — no polling, negligible idle CPU.
/// </summary>
public interface IApplicationTracker : IDisposable
{
    event EventHandler<ApplicationChangedEventArgs>? ApplicationChanged;

    void Start();

    void Stop();
}
