using System.Reflection;
using System.Text.Json;
using Microsoft.Extensions.Options;
using Treck.Agent.Activity;
using Treck.Agent.Configuration;
using Treck.Agent.Offline;
using Treck.Agent.Services;
using Treck.Agent.Sessions;

namespace Treck.Agent;

/// <summary>
/// The agent's long-running background service / orchestrator.
///
/// Through Milestone 4: ensures the device is registered (M2), starts the
/// session monitor (M3) and the heartbeat scheduler (M4), and logs the events
/// each produces. Session and heartbeat events are observed internally ONLY —
/// nothing is sent to the API yet (that begins in M5). Screenshots and
/// application-usage tracking are not implemented.
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
    private readonly IOfflineEventStore _eventStore;

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        IDeviceRegistrationService registration,
        ISessionMonitor sessionMonitor,
        IHeartbeatScheduler heartbeatScheduler,
        IOfflineEventStore eventStore)
    {
        _logger = logger;
        _options = options.Value;
        _registration = registration;
        _sessionMonitor = sessionMonitor;
        _heartbeatScheduler = heartbeatScheduler;
        _eventStore = eventStore;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s",
            AgentVersion, _options.BaseUrl, _options.EmployeeCode, _options.HeartbeatIntervalSeconds);

        _eventStore.Initialize();
        _sessionMonitor.SessionChanged += OnSessionChanged;
        _heartbeatScheduler.HeartbeatProduced += OnHeartbeatProduced;
        _sessionMonitor.Start();
        _heartbeatScheduler.Start();

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
            _sessionMonitor.SessionChanged -= OnSessionChanged;
            _heartbeatScheduler.HeartbeatProduced -= OnHeartbeatProduced;
            _heartbeatScheduler.Stop();
            _sessionMonitor.Stop();
        }

        _logger.LogInformation("Treck Agent stopped.");
    }

    private void OnSessionChanged(object? sender, SessionEvent sessionEvent)
    {
        // M5: persist to the offline queue; the SyncWorker ships it. The Worker
        // never calls the API directly.
        Enqueue(OfflineEventKind.Session, sessionEvent, sessionEvent.TimestampUtc);
    }

    private void OnHeartbeatProduced(object? sender, HeartbeatEvent heartbeat)
    {
        Enqueue(OfflineEventKind.Heartbeat, heartbeat, heartbeat.TimestampUtc);
    }

    private void Enqueue(OfflineEventKind kind, object payload, DateTimeOffset createdAtUtc)
    {
        try
        {
            var json = JsonSerializer.Serialize(payload);
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
