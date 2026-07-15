using Microsoft.Win32;

namespace Treck.Agent.Services;

/// <summary>
/// Tracks workstation lock/unlock and logoff/shutdown so activity can be
/// classified as "locked" and the session closed at the right time.
///
/// Uses Microsoft.Win32.SystemEvents, which requires an interactive session
/// with a message pump — i.e. the user-session helper (see doc 17).
/// </summary>
public sealed class SessionMonitor : IDisposable
{
    /// <summary>True while the desktop is locked.</summary>
    public bool IsLocked { get; private set; }

    /// <summary>Raised on logoff / system shutdown so the session can be closed.</summary>
    public event Action? SessionEnding;

    public void Start()
    {
        SystemEvents.SessionSwitch += OnSessionSwitch;
        SystemEvents.SessionEnding += OnSessionEnding;
    }

    private void OnSessionSwitch(object sender, SessionSwitchEventArgs e)
    {
        switch (e.Reason)
        {
            case SessionSwitchReason.SessionLock:
                IsLocked = true;
                break;
            case SessionSwitchReason.SessionUnlock:
                IsLocked = false;
                break;
            case SessionSwitchReason.SessionLogoff:
                SessionEnding?.Invoke();
                break;
        }
    }

    private void OnSessionEnding(object sender, SessionEndingEventArgs e)
    {
        SessionEnding?.Invoke();
    }

    public void Dispose()
    {
        SystemEvents.SessionSwitch -= OnSessionSwitch;
        SystemEvents.SessionEnding -= OnSessionEnding;
    }
}
