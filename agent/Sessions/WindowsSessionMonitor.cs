using System.Runtime.Versioning;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.Win32;

namespace Treck.Agent.Sessions;

/// <summary>
/// Windows implementation using <see cref="SystemEvents"/> — a managed wrapper
/// over the native session/shutdown notifications (WM_WTSSESSION_CHANGE /
/// WM_ENDSESSION). Purely event-driven; SystemEvents runs its own message loop,
/// so there is no polling.
///
/// Note: SystemEvents delivers session-switch events to the interactive desktop
/// session, so this is intended to run in the per-user session context (the
/// helper described in doc 17). A pure session-0 service would instead hook
/// ServiceBase.OnSessionChange; the <see cref="SessionMonitorBase"/> contract is
/// identical either way.
///
/// Restart vs. shutdown is not reliably distinguishable through this API — the
/// system-ending notification surfaces as <see cref="SessionEventType.Shutdown"/>.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsSessionMonitor : SessionMonitorBase
{
    public WindowsSessionMonitor(
        ILogger<WindowsSessionMonitor> logger,
        IOptions<SessionMonitorOptions> options,
        TimeProvider timeProvider)
        : base(logger, options.Value, timeProvider)
    {
    }

    protected override void OnStart()
    {
        SystemEvents.SessionSwitch += OnSessionSwitch;
        SystemEvents.SessionEnding += OnSessionEnding;
    }

    protected override void OnStop()
    {
        SystemEvents.SessionSwitch -= OnSessionSwitch;
        SystemEvents.SessionEnding -= OnSessionEnding;
    }

    private void OnSessionSwitch(object sender, SessionSwitchEventArgs e)
    {
        var type = e.Reason switch
        {
            SessionSwitchReason.SessionLogon => SessionEventType.Logon,
            SessionSwitchReason.SessionLogoff => SessionEventType.Logoff,
            SessionSwitchReason.SessionLock => SessionEventType.Lock,
            SessionSwitchReason.SessionUnlock => SessionEventType.Unlock,
            _ => SessionEventType.Unknown,
        };

        if (type != SessionEventType.Unknown)
        {
            Publish(type);
        }
    }

    private void OnSessionEnding(object sender, SessionEndingEventArgs e)
    {
        var type = e.Reason == SessionEndReasons.Logoff
            ? SessionEventType.Logoff
            : SessionEventType.Shutdown; // SystemShutdown (restart is indistinguishable here)

        Publish(type);
    }
}
