using System.Reflection;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;
using Treck.Agent.Services;

namespace Treck.Agent;

/// <summary>
/// The agent's long-running background service.
///
/// Milestone 2 scope: on startup, ensure the device is registered (decrypting a
/// stored token or registering to obtain one), retrying each interval until it
/// succeeds. Session detection (M3), idle + heartbeat payloads (M4), and offline
/// caching (M5) are still deferred — the periodic tick is a placeholder.
/// </summary>
public sealed class Worker : BackgroundService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ILogger<Worker> _logger;
    private readonly AgentOptions _options;
    private readonly IDeviceRegistrationService _registration;

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        IDeviceRegistrationService registration)
    {
        _logger = logger;
        _options = options.Value;
        _registration = registration;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s",
            AgentVersion, _options.BaseUrl, _options.EmployeeCode, _options.HeartbeatIntervalSeconds);

        await TryEnsureRegisteredAsync(stoppingToken);

        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds));

        try
        {
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

        _logger.LogInformation("Treck Agent stopped.");
    }

    private async Task TryEnsureRegisteredAsync(CancellationToken cancellationToken)
    {
        try
        {
            await _registration.EnsureRegisteredAsync(cancellationToken);
        }
        catch (Exception ex) when (!cancellationToken.IsCancellationRequested)
        {
            // Full network-outage handling (offline cache/reconnect) is M5; for now
            // we log and retry on the next tick.
            _logger.LogWarning("Device not registered yet; will retry next interval. Reason: {Reason}", ex.Message);
        }
    }
}
