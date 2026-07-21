using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Activity;
using Treck.Agent.Applications;
using Treck.Agent.Configuration;

namespace Treck.Agent.Screenshots;

// Phase 8 #3: captures are stamped with the collecting EventSource before submit.

/// <summary>
/// Hosted background service that drives screenshot capture (Phase 8) on its own
/// cadence, independent of the heartbeat/presence/app-tracking loops so it never
/// blocks them. Each cycle:
///
///   1. evaluate policy (enabled, interactive desktop, active user, not ignored);
///   2. capture each monitor;
///   3. compress + hash + dedup + write temp file (ScreenshotProcessingService);
///   4. hand each survivor to the <see cref="IScreenshotSink"/>.
///
/// The sink either enqueues to the offline queue directly (in-process mode) or
/// spools it for the Session-0 service (helper mode). Either way the existing
/// SyncWorker drains the queue and uploads; nothing is uploaded synchronously
/// here. Interval is fixed or randomized per options.
///
/// This worker must run in the INTERACTIVE user session — a Session-0 service
/// cannot read the user's desktop. In the Windows-service deployment it runs
/// inside the capture helper the service launches into the active session; see
/// <see cref="ScreenshotHelperSupervisor"/> and docs/27-screenshot-module.md.
/// </summary>
public sealed class ScreenshotWorker : BackgroundService
{
    private readonly ILogger<ScreenshotWorker> _logger;
    private readonly ScreenshotOptions _options;
    private readonly TimeSpan _idleThreshold;
    private readonly IScreenshotCaptureService _capture;
    private readonly IScreenshotProcessingService _processing;
    private readonly IActiveWindowService _activeWindow;
    private readonly IIdleDetector _idleDetector;
    private readonly IScreenshotSink _sink;
    private readonly EventSource _source;

    public ScreenshotWorker(
        ILogger<ScreenshotWorker> logger,
        IOptions<ScreenshotOptions> options,
        IOptions<AgentOptions> agentOptions,
        IScreenshotCaptureService capture,
        IScreenshotProcessingService processing,
        IActiveWindowService activeWindow,
        IIdleDetector idleDetector,
        IScreenshotSink sink,
        EventSource source)
    {
        _logger = logger;
        _options = options.Value;
        _idleThreshold = TimeSpan.FromSeconds(agentOptions.Value.IdleThresholdSeconds);
        _capture = capture;
        _processing = processing;
        _activeWindow = activeWindow;
        _idleDetector = idleDetector;
        _sink = sink;
        _source = source;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        if (!_options.Enabled)
        {
            _logger.LogInformation("Screenshot capture is disabled by configuration.");
            return;
        }

        _logger.LogInformation(
            "Screenshot worker started (interval={Interval}s, jitter={Jitter}s, format={Format}, multiMonitor={Multi}).",
            _options.IntervalSeconds, _options.RandomJitterSeconds, _options.Format, _options.MultiMonitor);

        // Probe capture availability now so any session-0 isolation is reported at
        // startup (the capture service logs the reason on the first evaluation),
        // instead of silently after the first interval elapses.
        _ = _capture.CanCapture();

        try
        {
            while (!stoppingToken.IsCancellationRequested)
            {
                await Task.Delay(NextDelay(), stoppingToken);

                try
                {
                    CaptureCycle();
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Screenshot capture cycle failed.");
                }
            }
        }
        catch (OperationCanceledException)
        {
            // Expected on graceful shutdown.
        }

        _logger.LogInformation("Screenshot worker stopped.");
    }

    /// <summary>Runs one capture cycle if policy allows. Internal for unit testing intent.</summary>
    private void CaptureCycle()
    {
        if (!ShouldCapture())
        {
            return;
        }

        var active = _activeWindow.GetActiveApplication();

        if (IsIgnored(active?.ProcessName, active?.ExecutableName, active?.WindowTitle))
        {
            _logger.LogDebug("Skipping capture: foreground app is on the ignore list.");
            return;
        }

        // One capture-session id ties a multi-monitor set together server-side.
        var sessionId = Guid.NewGuid().ToString("N");
        var capturedAt = DateTimeOffset.UtcNow;

        var captures = _capture.CaptureAll();

        if (captures.Count == 0)
        {
            _logger.LogWarning("Capture cycle produced 0 monitors (capture unavailable or enumeration failed).");
            return;
        }

        var queued = 0;
        var deduped = 0;

        foreach (var monitor in captures)
        {
            using (monitor)
            {
                _logger.LogDebug("Captured monitor {Monitor}: {Width}x{Height}.",
                    monitor.MonitorNumber, monitor.Image.Width, monitor.Image.Height);

                var metadata = _processing.Process(
                    monitor,
                    active?.ProcessName,
                    active?.WindowTitle,
                    sessionId,
                    capturedAt);

                if (metadata is not null)
                {
                    // Stamp where this capture was collected (Phase 8 #3).
                    metadata = metadata with
                    {
                        SourceSessionId = _source.SessionId,
                        SourceUser = _source.User,
                        SourceProcess = _source.Process,
                        CollectionMode = _source.CollectionMode,
                    };
                    Submit(metadata);
                    queued++;
                }
                else
                {
                    deduped++;
                }
            }
        }

        _logger.LogInformation(
            "Capture cycle complete: monitors={Monitors} queued={Queued} unchanged={Deduped} session={Session}.",
            captures.Count, queued, deduped, sessionId);
    }

    private bool ShouldCapture()
    {
        // Authoritative guard for lock / logged-out / secure desktop.
        if (!_capture.CanCapture())
        {
            return false;
        }

        if (_options.CaptureOnlyWhenActive && _idleDetector.GetIdleTime() >= _idleThreshold)
        {
            _logger.LogDebug("Skipping capture: user is idle.");
            return false;
        }

        return true;
    }

    private bool IsIgnored(string? processName, string? executable, string? windowTitle)
    {
        foreach (var ignored in _options.IgnoredProcesses)
        {
            var bare = ignored.Replace(".exe", string.Empty, StringComparison.OrdinalIgnoreCase);

            if (Matches(processName, bare) || Matches(executable, ignored) || Matches(executable, bare))
            {
                return true;
            }
        }

        if (!string.IsNullOrEmpty(windowTitle))
        {
            foreach (var needle in _options.IgnoredWindowTitles)
            {
                if (!string.IsNullOrEmpty(needle)
                    && windowTitle.Contains(needle, StringComparison.OrdinalIgnoreCase))
                {
                    return true;
                }
            }
        }

        return false;
    }

    private static bool Matches(string? value, string candidate)
        => !string.IsNullOrEmpty(value) && string.Equals(value, candidate, StringComparison.OrdinalIgnoreCase);

    private void Submit(ScreenshotMetadata metadata)
    {
        try
        {
            _sink.Submit(metadata);
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Failed to submit screenshot to the sink.");
        }
    }

    /// <summary>Fixed interval, optionally jittered by up to RandomJitterSeconds.</summary>
    private TimeSpan NextDelay()
    {
        var seconds = _options.IntervalSeconds;

        if (_options.RandomJitterSeconds > 0)
        {
            seconds += Random.Shared.Next(0, _options.RandomJitterSeconds + 1);
        }

        return TimeSpan.FromSeconds(seconds);
    }
}
