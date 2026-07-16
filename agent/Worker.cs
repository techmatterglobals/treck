using System.Reflection;
using Microsoft.Extensions.Options;
using Treck.Agent.Activity;
using Treck.Agent.Configuration;
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

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        IDeviceRegistrationService registration,
        ISessionMonitor sessionMonitor,
        IHeartbeatScheduler heartbeatScheduler)
    {
        _logger = logger;
        _options = options.Value;
        _registration = registration;
        _sessionMonitor = sessionMonitor;
        _heartbeatScheduler = heartbeatScheduler;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s",
            AgentVersion, _options.BaseUrl, _options.EmployeeCode, _options.HeartbeatIntervalSeconds);

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
        // M3/M4: observe only — no API calls.
        _logger.LogDebug(
            "Worker observed session event {Type} at {Timestamp:o}",
            sessionEvent.Type, sessionEvent.TimestampUtc);
    }

    private void OnHeartbeatProduced(object? sender, HeartbeatEvent heartbeat)
    {
        // M4: observe only — sending to the API begins in M5.
        _logger.LogDebug(
            "Worker observed heartbeat active={ActiveSeconds}s idle={IdleSeconds}s isIdle={IsIdle}",
            heartbeat.ActiveSeconds, heartbeat.IdleSeconds, heartbeat.IsIdle);
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
