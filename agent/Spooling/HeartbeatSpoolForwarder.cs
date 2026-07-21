using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Treck.Agent.Activity;
using Treck.Agent.Offline;

namespace Treck.Agent.Spooling;

/// <summary>
/// Runs the heartbeat scheduler INSIDE the interactive capture helper so idle
/// detection (Win32 <c>GetLastInputInfo</c>) reflects the real user session —
/// a Session-0 service always reads "no input" and would report the user idle
/// forever. Each heartbeat is written to the spool as a <c>Heartbeat</c> event;
/// the service ingests and uploads it through the existing pipeline.
///
/// Consequence: heartbeats flow only while a user is logged in interactively.
/// When no one is logged in there is no heartbeat (the device presents as
/// offline/logged-out), which matches user-presence semantics.
/// </summary>
public sealed class HeartbeatSpoolForwarder : IHostedService
{
    private readonly ILogger<HeartbeatSpoolForwarder> _logger;
    private readonly IHeartbeatScheduler _scheduler;
    private readonly IAgentEventSpool _spool;

    public HeartbeatSpoolForwarder(
        ILogger<HeartbeatSpoolForwarder> logger,
        IHeartbeatScheduler scheduler,
        IAgentEventSpool spool)
    {
        _logger = logger;
        _scheduler = scheduler;
        _spool = spool;
    }

    public Task StartAsync(CancellationToken cancellationToken)
    {
        _scheduler.HeartbeatProduced += OnHeartbeat;
        _scheduler.Start();
        _logger.LogInformation("Heartbeat collection running in the interactive helper.");
        return Task.CompletedTask;
    }

    public Task StopAsync(CancellationToken cancellationToken)
    {
        _scheduler.Stop();
        _scheduler.HeartbeatProduced -= OnHeartbeat;
        return Task.CompletedTask;
    }

    private void OnHeartbeat(object? sender, HeartbeatEvent heartbeat)
    {
        try
        {
            var json = JsonSerializer.Serialize(heartbeat);
            _spool.Submit(OfflineEvent.Create(OfflineEventKind.Heartbeat, json, heartbeat.TimestampUtc));
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Failed to spool heartbeat.");
        }
    }
}
