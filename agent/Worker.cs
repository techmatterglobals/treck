using System.Runtime.InteropServices;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;
using Treck.Agent.Models;
using Treck.Agent.Services;

namespace Treck.Agent;

/// <summary>
/// Orchestrates the agent lifecycle: ensure registered → open a session on
/// start → report activity every interval → close the session on stop.
///
/// In production the input sensing (IdleDetector/SessionMonitor) belongs in the
/// interactive-session helper; this Worker shows the end-to-end flow and the
/// networking that the service owns (see doc 17).
/// </summary>
public sealed class Worker : BackgroundService
{
    private readonly ILogger<Worker> _logger;
    private readonly AgentOptions _options;
    private readonly ApiClient _api;
    private readonly TokenStore _tokens;
    private readonly IdleDetector _idle;
    private readonly SessionMonitor _session;
    private readonly ActivityTracker _tracker;

    private long _sessionId;

    public Worker(
        ILogger<Worker> logger,
        IOptions<AgentOptions> options,
        ApiClient api,
        TokenStore tokens,
        IdleDetector idle,
        SessionMonitor session,
        ActivityTracker tracker)
    {
        _logger = logger;
        _options = options.Value;
        _api = api;
        _tokens = tokens;
        _idle = idle;
        _session = session;
        _tracker = tracker;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _session.Start();

        await EnsureRegisteredAsync(stoppingToken);
        await OpenSessionAsync(stoppingToken);

        var last = DateTimeOffset.UtcNow;
        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds));

        while (await timer.WaitForNextTickAsync(stoppingToken))
        {
            var now = DateTimeOffset.UtcNow;
            var elapsed = (int)(now - last).TotalSeconds;
            last = now;

            var sample = _tracker.Classify(
                elapsed,
                _idle.GetIdleSeconds(),
                _session.IsLocked,
                _options.IdleThresholdSeconds);

            try
            {
                await _api.SendActivityAsync(
                    _sessionId, sample.ActiveSeconds, sample.IdleSeconds, sample.Status, stoppingToken);
            }
            catch (UnauthorizedAccessException)
            {
                _logger.LogWarning("Token rejected; re-registering.");
                _tokens.Clear();
                await EnsureRegisteredAsync(stoppingToken);
                await OpenSessionAsync(stoppingToken);
            }
            catch (Exception ex)
            {
                // ApiClient already retried; log and keep the loop alive.
                // Production: enqueue `sample` to a local buffer and flush later.
                _logger.LogError(ex, "Activity report failed; will continue.");
            }
        }
    }

    public override async Task StopAsync(CancellationToken cancellationToken)
    {
        try
        {
            if (_sessionId != 0)
            {
                await _api.LogoutAsync(_sessionId, 0, 0, cancellationToken);
            }
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Logout on shutdown failed.");
        }

        _session.Dispose();
        await base.StopAsync(cancellationToken);
    }

    private async Task EnsureRegisteredAsync(CancellationToken ct)
    {
        if (_tokens.HasToken)
        {
            return;
        }

        _logger.LogInformation("Registering device {Uuid}.", _tokens.DeviceUuid);
        var data = await _api.RegisterAsync(Environment.MachineName, RuntimeInformation.OSDescription, ct);
        _tokens.Save(data.Token, data.EmployeeId, data.ComputerId);
    }

    private async Task OpenSessionAsync(CancellationToken ct)
    {
        _sessionId = await _api.LoginAsync(Environment.MachineName, ct);
        _logger.LogInformation("Opened session {SessionId}.", _sessionId);
    }
}
