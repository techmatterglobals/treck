using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Treck.Agent.Applications;

/// <summary>
/// The application-usage state machine — platform-agnostic and unit-testable
/// without a real desktop. It holds at most one <em>open</em> session and turns
/// a stream of foreground snapshots into completed sessions:
///
/// <list type="bullet">
///   <item>same process + same title  → keep the open session (no-op);</item>
///   <item>different process OR title  → close the open session, open a new one;</item>
///   <item>null / ignored app         → close the open session, open nothing.</item>
/// </list>
///
/// A session is emitted (via <see cref="SessionCompleted"/>) only when it ends,
/// so per-second sampling is never transmitted. Sessions below the configured
/// minimum duration are dropped as noise. Only usage metadata is ever handled —
/// no keystrokes, clipboard, screen or file contents.
/// </summary>
public sealed class ApplicationSessionManager : IApplicationSessionManager
{
    private readonly ILogger<ApplicationSessionManager> _logger;
    private readonly ApplicationTrackingOptions _options;
    private readonly TimeProvider _timeProvider;
    private readonly object _gate = new();

    private ApplicationInfo? _openApp;
    private DateTimeOffset _openedAtUtc;
    private string _openSessionId = string.Empty;

    public event EventHandler<ApplicationUsageEvent>? SessionCompleted;

    public ApplicationSessionManager(
        ILogger<ApplicationSessionManager> logger,
        IOptions<ApplicationTrackingOptions> options,
        TimeProvider timeProvider)
    {
        _logger = logger;
        _options = options.Value;
        _timeProvider = timeProvider;
    }

    public void Track(ApplicationInfo? current, DateTimeOffset nowUtc)
    {
        // An ignored application is treated exactly like "no foreground window":
        // it ends the current session and starts nothing.
        if (current is not null && IsIgnored(current))
        {
            current = null;
        }

        ApplicationUsageEvent? completed = null;

        lock (_gate)
        {
            var sameSession = _openApp is not null
                && current is not null
                && string.Equals(_openApp.SessionKey, current.SessionKey, StringComparison.Ordinal);

            if (sameSession)
            {
                return; // Still on the same app + title: nothing to do.
            }

            completed = CloseLocked(nowUtc);

            if (current is not null)
            {
                _openApp = current;
                _openedAtUtc = nowUtc;
                _openSessionId = Guid.NewGuid().ToString("N");
            }
        }

        Emit(completed);
    }

    public void Flush(DateTimeOffset nowUtc)
    {
        ApplicationUsageEvent? completed;

        lock (_gate)
        {
            completed = CloseLocked(nowUtc);
        }

        Emit(completed);
    }

    /// <summary>
    /// Closes the open session (if any) and returns the completed event when it
    /// is worth keeping, else null. Caller holds <see cref="_gate"/>.
    /// </summary>
    private ApplicationUsageEvent? CloseLocked(DateTimeOffset nowUtc)
    {
        if (_openApp is null)
        {
            return null;
        }

        var app = _openApp;
        var startedAt = _openedAtUtc;
        var sessionId = _openSessionId;

        _openApp = null;
        _openSessionId = string.Empty;

        // Guard against clock skew / out-of-order notifications.
        var endedAt = nowUtc >= startedAt ? nowUtc : startedAt;
        var duration = (int)Math.Round((endedAt - startedAt).TotalSeconds, MidpointRounding.AwayFromZero);

        if (duration < _options.MinimumSessionSeconds)
        {
            _logger.LogDebug(
                "Dropping sub-threshold session {Process} ({Duration}s < {Min}s).",
                app.ProcessName, duration, _options.MinimumSessionSeconds);
            return null;
        }

        return new ApplicationUsageEvent(
            SessionId: sessionId,
            ProcessName: app.ProcessName,
            ExecutableName: app.ExecutableName,
            WindowTitle: Truncate(app.WindowTitle, _options.MaxWindowTitleLength),
            ProcessId: app.ProcessId,
            StartedAt: startedAt,
            EndedAt: endedAt,
            DurationSeconds: duration,
            UserSession: CurrentSessionId(),
            IsSystemProcess: app.IsSystemProcess);
    }

    private void Emit(ApplicationUsageEvent? completed)
    {
        if (completed is null)
        {
            return;
        }

        _logger.LogInformation(
            "App session: {Process} \"{Title}\" for {Duration}s.",
            completed.ProcessName, completed.WindowTitle, completed.DurationSeconds);

        SessionCompleted?.Invoke(this, completed);
    }

    private bool IsIgnored(ApplicationInfo app)
    {
        foreach (var exe in _options.IgnoredExecutables)
        {
            if (!string.IsNullOrEmpty(app.ExecutableName)
                && string.Equals(exe, app.ExecutableName, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        foreach (var name in _options.IgnoredProcessNames)
        {
            if (string.Equals(name, app.ProcessName, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        return false;
    }

    /// <summary>The interactive Windows session id (0 off Windows / in tests).</summary>
    private static int CurrentSessionId()
    {
        try
        {
            return System.Diagnostics.Process.GetCurrentProcess().SessionId;
        }
        catch
        {
            return 0;
        }
    }

    private static string Truncate(string value, int max)
    {
        if (max <= 0 || value.Length <= max)
        {
            return value;
        }

        return new StringBuilder(value, 0, max, max).ToString();
    }
}
