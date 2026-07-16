using System.Reflection;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;
using Treck.Agent.Services;
using Treck.Agent.Sessions;

namespace Treck.Agent;

/// <summary>
/// The agent's long-running background service.
///
/// Through Milestone 3: ensures the device is registered (M2) and starts the
/// session monitor (M3), logging every detected session event. Session events
/// are only observed internally here — they are NOT yet sent to the API. Idle
/// time + heartbeat payloads (M4) and offline caching (M5) are still deferred.
/// </summary>
public sealed class Worker : BackgroundService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ILogger<Worker> _logger;
    private readonly AgentOptions _options;
    private readonly IDeviceRegistrationService _registration;
    private readonly ISessionMonitor _sessionMonitor;

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        IDeviceRegistrationService registration,
        ISessionMonitor sessionMonitor)
    {
        _logger = logger;
        _options = options.Value;
        _registration = registration;
        _sessionMonitor = sessionMonitor;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s",
            AgentVersion, _options.BaseUrl, _options.EmployeeCode, _options.HeartbeatIntervalSeconds);

        _sessionMonitor.SessionChanged += OnSessionChanged;
        _sessionMonitor.Start();

        try
        {
            await TryEnsureRegisteredAsync(stoppingToken);

            using var timer = new PeriodicTimer(TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds));

            while (await timer.WaitForNextTickAsync(stoppingToken))
            {
                if (!_registration.IsRegistered)
                {
                    await TryEnsureRegisteredAsync(stoppingToken);
                }
                else
                {
                    _logger.LogDebug("Agent alive tick at {Timestamp:o} (registered)", DateTimeOffset.Now);
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
            _sessionMonitor.Stop();
        }

        _logger.LogInformation("Treck Agent stopped.");
    }

    private void OnSessionChanged(object? sender, SessionEvent sessionEvent)
    {
        // Milestone 3: observe only — no API calls. Sending happens from M4.
        _logger.LogDebug(
            "Worker observed session event {Type} at {Timestamp:o}",
            sessionEvent.Type, sessionEvent.TimestampUtc);
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
