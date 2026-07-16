using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;

namespace Treck.Agent.Activity;

/// <summary>
/// Drives the heartbeat cadence with a <see cref="PeriodicTimer"/> (using the
/// injected <see cref="TimeProvider"/> so the interval is testable). Each tick
/// reads the idle detector, classifies the interval via
/// <see cref="HeartbeatCalculator"/>, logs it, and raises the event. It knows
/// nothing about the API — heartbeats are published internally only.
/// </summary>
public sealed class HeartbeatScheduler : IHeartbeatScheduler
{
    private readonly ILogger<HeartbeatScheduler> _logger;
    private readonly IIdleDetector _idleDetector;
    private readonly TimeProvider _timeProvider;
    private readonly TimeSpan _interval;
    private readonly TimeSpan _idleThreshold;
    private readonly object _gate = new();

    private CancellationTokenSource? _cts;
    private Task? _loop;
    private DateTimeOffset? _lastCaptureAt;

    public event EventHandler<HeartbeatEvent>? HeartbeatProduced;

    public HeartbeatScheduler(
        ILogger<HeartbeatScheduler> logger,
        IIdleDetector idleDetector,
        IOptions<AgentOptions> options,
        TimeProvider timeProvider)
    {
        _logger = logger;
        _idleDetector = idleDetector;
        _timeProvider = timeProvider;
        _interval = TimeSpan.FromSeconds(options.Value.HeartbeatIntervalSeconds);
        _idleThreshold = TimeSpan.FromSeconds(options.Value.IdleThresholdSeconds);
    }

    public void Start()
    {
        lock (_gate)
        {
            if (_loop is not null)
            {
                return;
            }

            _cts = new CancellationTokenSource();
            _loop = Task.Run(() => RunAsync(_cts.Token));
        }

        _logger.LogInformation(
            "Heartbeat scheduler started (interval={IntervalSeconds}s, idleThreshold={ThresholdSeconds}s).",
            _interval.TotalSeconds, _idleThreshold.TotalSeconds);
    }

    public void Stop()
    {
        CancellationTokenSource? cts;
        Task? loop;

        lock (_gate)
        {
            cts = _cts;
            loop = _loop;
            _cts = null;
            _loop = null;
        }

        if (cts is null)
        {
            return;
        }

        cts.Cancel();

        try
        {
            loop?.Wait(TimeSpan.FromSeconds(5));
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Heartbeat loop did not stop cleanly.");
        }

        cts.Dispose();
        _logger.LogInformation("Heartbeat scheduler stopped.");
    }

    private async Task RunAsync(CancellationToken cancellationToken)
    {
        using var timer = new PeriodicTimer(_interval, _timeProvider);

        try
        {
            while (await timer.WaitForNextTickAsync(cancellationToken))
            {
                try
                {
                    CaptureHeartbeat();
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Heartbeat capture failed.");
                }
            }
        }
        catch (OperationCanceledException)
        {
            // Expected on Stop().
        }
    }

    /// <summary>
    /// Takes one heartbeat sample and raises the event. Public so it can be unit
    /// tested directly (the loop calls it once per interval).
    /// </summary>
    public HeartbeatEvent CaptureHeartbeat()
    {
        HeartbeatEvent heartbeat;

        lock (_gate)
        {
            var now = _timeProvider.GetUtcNow();
            var elapsed = _lastCaptureAt is { } last ? now - last : _interval;
            _lastCaptureAt = now;

            heartbeat = HeartbeatCalculator.Create(now, elapsed, _idleDetector.GetIdleTime(), _idleThreshold);
        }

        _logger.LogInformation(
            "Heartbeat: active={ActiveSeconds}s idle={IdleSeconds}s isIdle={IsIdle} observedIdle={ObservedIdle}s",
            heartbeat.ActiveSeconds, heartbeat.IdleSeconds, heartbeat.IsIdle, heartbeat.IdleTimeSeconds);

        HeartbeatProduced?.Invoke(this, heartbeat);

        return heartbeat;
    }

    public void Dispose()
    {
        Stop();
        GC.SuppressFinalize(this);
    }
}
