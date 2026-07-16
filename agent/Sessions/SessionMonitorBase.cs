using Microsoft.Extensions.Logging;

namespace Treck.Agent.Sessions;

/// <summary>
/// Platform-agnostic core of a session monitor: thread-safe start/stop,
/// duplicate suppression, structured logging, and event publication. The native
/// wiring (which OS notifications to subscribe to) lives in derived classes,
/// keeping this logic unit-testable without a real Windows session.
/// </summary>
public abstract class SessionMonitorBase : ISessionMonitor
{
    private readonly ILogger _logger;
    private readonly TimeProvider _timeProvider;
    private readonly TimeSpan _suppressionWindow;
    private readonly object _gate = new();

    private SessionEventType? _lastType;
    private DateTimeOffset _lastAt;
    private bool _started;

    public event EventHandler<SessionEvent>? SessionChanged;

    protected SessionMonitorBase(ILogger logger, SessionMonitorOptions options, TimeProvider timeProvider)
    {
        _logger = logger;
        _timeProvider = timeProvider;
        _suppressionWindow = TimeSpan.FromMilliseconds(options.DuplicateSuppressionMilliseconds);
    }

    public void Start()
    {
        lock (_gate)
        {
            if (_started)
            {
                return;
            }

            _started = true;
        }

        OnStart();
        _logger.LogInformation("Session monitor started.");
    }

    public void Stop()
    {
        lock (_gate)
        {
            if (!_started)
            {
                return;
            }

            _started = false;
        }

        OnStop();
        _logger.LogInformation("Session monitor stopped.");
    }

    /// <summary>Subscribe to native notifications.</summary>
    protected abstract void OnStart();

    /// <summary>Unsubscribe from native notifications.</summary>
    protected abstract void OnStop();

    /// <summary>
    /// Called by derived classes when a native session notification arrives.
    /// De-duplicates consecutive identical events, then raises SessionChanged.
    /// </summary>
    protected void Publish(SessionEventType type)
    {
        SessionEvent sessionEvent;

        lock (_gate)
        {
            var now = _timeProvider.GetUtcNow();

            if (_lastType == type && (now - _lastAt) < _suppressionWindow)
            {
                _logger.LogDebug("Ignoring duplicate {Type} session event.", type);
                return;
            }

            _lastType = type;
            _lastAt = now;
            sessionEvent = new SessionEvent(type, now);
        }

        // Raise outside the lock so handlers can't deadlock/reenter the monitor.
        _logger.LogInformation("Session event: {Type} at {Timestamp:o}", sessionEvent.Type, sessionEvent.TimestampUtc);
        SessionChanged?.Invoke(this, sessionEvent);
    }

    public void Dispose()
    {
        Stop();
        GC.SuppressFinalize(this);
    }
}
