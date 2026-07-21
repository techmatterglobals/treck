using System.Reflection;
using System.Text.Json;
using Microsoft.Extensions.Options;
using Treck.Agent.Activity;
using Treck.Agent.Applications;
using Treck.Agent.Configuration;
using Treck.Agent.Offline;
using Treck.Agent.Services;
using Treck.Agent.Sessions;
using Treck.Agent.Spooling;

namespace Treck.Agent;

/// <summary>
/// The agent's long-running background service / orchestrator.
///
/// Ensures the device is registered (M2), starts the session monitor (M3), the
/// heartbeat scheduler (M4) and the application tracker (Phase 7), and enqueues
/// the events each produces onto the offline queue for the SyncWorker to ship.
/// The Worker never calls the API directly.
///
/// Application usage: the tracker raises ApplicationChanged (WinEvent-driven, no
/// polling); the session manager turns that stream into completed sessions and
/// raises SessionCompleted, which is enqueued as an app_usage event. A lock,
/// logoff or shutdown flushes the open session so it is not lost. Screenshots and
/// any form of input capture remain out of scope.
/// </summary>
public sealed class Worker : BackgroundService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ILogger<Worker> _logger;
    private readonly AgentOptions _options;
    private readonly IDeviceRegistrationService _registration;
    private readonly ISessionMonitor _sessionMonitor;
    private readonly IHeartbeatScheduler _heartbeatScheduler;
    private readonly IApplicationTracker _applicationTracker;
    private readonly IApplicationSessionManager _applicationSessions;
    private readonly ApplicationTrackingOptions _appTrackingOptions;
    private readonly IOfflineEventStore _eventStore;

    // True when heartbeat/idle + app-usage collection run here; false when the
    // Session-0 service has delegated them to the interactive capture helper.
    private readonly bool _collectInteractive;
    private readonly EventSource _source;

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        IDeviceRegistrationService registration,
        ISessionMonitor sessionMonitor,
        IHeartbeatScheduler heartbeatScheduler,
        IApplicationTracker applicationTracker,
        IApplicationSessionManager applicationSessions,
        IOptions<ApplicationTrackingOptions> appTrackingOptions,
        IOfflineEventStore eventStore,
        AgentRuntime runtime,
        EventSource source)
    {
        _logger = logger;
        _options = options.Value;
        _registration = registration;
        _sessionMonitor = sessionMonitor;
        _heartbeatScheduler = heartbeatScheduler;
        _applicationTracker = applicationTracker;
        _applicationSessions = applicationSessions;
        _appTrackingOptions = appTrackingOptions.Value;
        _eventStore = eventStore;
        _collectInteractive = runtime.CollectInteractiveInProcess;
        _source = source;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s",
            AgentVersion, _options.BaseUrl, _options.EmployeeCode, _options.HeartbeatIntervalSeconds);

        _eventStore.Initialize();
        _sessionMonitor.SessionChanged += OnSessionChanged;
        _sessionMonitor.Start();

        if (_collectInteractive)
        {
            // In-process interactive collection (console/dev, already interactive).
            _heartbeatScheduler.HeartbeatProduced += OnHeartbeatProduced;
            _heartbeatScheduler.Start();

            if (_appTrackingOptions.Enabled)
            {
                _applicationSessions.SessionCompleted += OnApplicationSessionCompleted;
                _applicationTracker.ApplicationChanged += OnApplicationChanged;
                _applicationTracker.Start();
            }
            else
            {
                _logger.LogInformation("Application tracking is disabled by configuration.");
            }
        }
        else
        {
            // Session-0 service: heartbeat/idle + app-usage collection run in the
            // interactive capture helper instead (they cannot see the user's
            // desktop from session 0). This Worker keeps session monitoring + sync.
            // Explicit proof that no interactive collector runs here (Phase 8 #6).
            _logger.LogInformation(
                "Interactive collectors disabled in service mode: ScreenshotWorker not hosted, " +
                "ApplicationSessionManager (foreground) not started, idle/heartbeat collector not started. " +
                "The interactive helper collects them; this service only ingests the spool and syncs.");
        }

        try
        {
            await TryEnsureRegisteredAsync(stoppingToken);

            // Registration-retry loop (the heartbeat cadence is owned by the scheduler).
            using var timer = new PeriodicTimer(TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds));

            while (await timer.WaitForNextTickAsync(stoppingToken))
            {
                if (!_registration.IsRegistered)
                {
                    await TryEnsureRegisteredAsync(stoppingToken);
                }
            }
        }
        catch (OperationCanceledException)
        {
            // Expected on graceful shutdown.
        }
        finally
        {
            if (_collectInteractive)
            {
                if (_appTrackingOptions.Enabled)
                {
                    _applicationTracker.Stop();
                    _applicationTracker.ApplicationChanged -= OnApplicationChanged;
                    // Ship the session that was open when we stopped.
                    _applicationSessions.Flush(DateTimeOffset.UtcNow);
                    _applicationSessions.SessionCompleted -= OnApplicationSessionCompleted;
                }

                _heartbeatScheduler.HeartbeatProduced -= OnHeartbeatProduced;
                _heartbeatScheduler.Stop();
            }

            _sessionMonitor.SessionChanged -= OnSessionChanged;
            _sessionMonitor.Stop();
        }

        _logger.LogInformation("Treck Agent stopped.");
    }

    private void OnSessionChanged(object? sender, SessionEvent sessionEvent)
    {
        // M5: persist to the offline queue; the SyncWorker ships it. The Worker
        // never calls the API directly.
        Enqueue(OfflineEventKind.Session, sessionEvent, sessionEvent.TimestampUtc);

        // When the desktop is no longer interactive, close the open app session
        // so its duration stops accruing against a screen the user cannot see.
        // (Only relevant when app tracking runs in-process; in the delegated
        // topology the helper owns the session state.)
        if (_collectInteractive && _appTrackingOptions.Enabled && sessionEvent.Type is
            SessionEventType.Lock or SessionEventType.Logoff or SessionEventType.Shutdown)
        {
            _applicationSessions.Flush(sessionEvent.TimestampUtc);
        }
    }

    private void OnHeartbeatProduced(object? sender, HeartbeatEvent heartbeat)
    {
        Enqueue(OfflineEventKind.Heartbeat, heartbeat, heartbeat.TimestampUtc);
    }

    private void OnApplicationChanged(object? sender, ApplicationChangedEventArgs change)
    {
        // Feed the foreground snapshot to the state machine; it decides whether
        // this closes a session and/or opens a new one.
        _applicationSessions.Track(change.Application, change.TimestampUtc);
    }

    private void OnApplicationSessionCompleted(object? sender, ApplicationUsageEvent session)
    {
        // Only completed sessions are transmitted.
        Enqueue(OfflineEventKind.AppUsage, session, session.EndedAt);
    }

    private void Enqueue(OfflineEventKind kind, object payload, DateTimeOffset createdAtUtc)
    {
        try
        {
            var json = SourceStamp.Apply(JsonSerializer.Serialize(payload), _source);
            _eventStore.Enqueue(OfflineEvent.Create(kind, json, createdAtUtc));
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Failed to enqueue {Kind} event.", kind);
        }
    }

    private async Task TryEnsureRegisteredAsync(CancellationToken cancellationToken)
    {
        try
        {
            await _registration.EnsureRegisteredAsync(cancellationToken);
        }
        catch (Exception ex) when (!cancellationToken.IsCancellationRequested)
        {
            _logger.LogWarning("Device not registered yet; will retry next interval. Reason: {Reason}", ex.Message);
        }
    }
}
