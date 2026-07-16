using System.Reflection;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;

namespace Treck.Agent;

/// <summary>
/// The agent's long-running background service.
///
/// Milestone 1 scope: prove out the service lifecycle, configuration binding,
/// and structured logging. It logs a startup banner and a periodic "alive" tick
/// at the configured heartbeat interval. Real behaviour arrives in later
/// milestones — device registration (M2), session detection (M3), idle +
/// heartbeat payloads (M4), and offline caching (M5).
/// </summary>
public sealed class Worker : BackgroundService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ILogger<Worker> _logger;
    private readonly AgentOptions _options;

    public Worker(ILogger<Worker> logger, IOptions<AgentOptions> options)
    {
        _logger = logger;
        _options = options.Value;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation(
            "Treck Agent started. Version={Version} Server={BaseUrl} EmployeeCode={EmployeeCode} Heartbeat={HeartbeatSeconds}s IdleThreshold={IdleSeconds}s",
            AgentVersion,
            _options.BaseUrl,
            _options.EmployeeCode,
            _options.HeartbeatIntervalSeconds,
            _options.IdleThresholdSeconds);

        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds));

        try
        {
            while (await timer.WaitForNextTickAsync(stoppingToken))
            {
                _logger.LogDebug("Agent alive tick at {Timestamp:o}", DateTimeOffset.Now);
            }
        }
        catch (OperationCanceledException)
        {
            // Expected on graceful shutdown.
        }

        _logger.LogInformation("Treck Agent stopped.");
    }
}
