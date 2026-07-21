using System.Runtime.Versioning;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Treck.Agent.Applications;

namespace Treck.Agent.Screenshots;

/// <summary>
/// One-shot capture validation for <c>--capture-helper-test</c>. Runs a single
/// capture cycle in the CURRENT session, logs a detailed per-monitor report
/// (dimensions, byte size, hash, temp path, foreground app) and exits — no loop,
/// no upload. Intended to be run interactively by an administrator to confirm the
/// capture pipeline works in the user session before enabling the module.
///
/// Exit code 0 = at least one monitor captured; 1 = capture unavailable
/// (e.g. run as a Session-0 service, or the secure/lock desktop is active).
/// </summary>
[SupportedOSPlatform("windows")]
public static class ScreenshotSelfTest
{
    public static int Run(IServiceProvider services)
    {
        var logger = services.GetRequiredService<ILoggerFactory>().CreateLogger("ScreenshotSelfTest");
        var capture = services.GetRequiredService<IScreenshotCaptureService>();
        var processing = services.GetRequiredService<IScreenshotProcessingService>();
        var activeWindow = services.GetRequiredService<IActiveWindowService>();

        logger.LogInformation(
            "Self-test: session={Session} user={User} pid={Pid}.",
            System.Diagnostics.Process.GetCurrentProcess().SessionId,
            Environment.UserName,
            Environment.ProcessId);

        if (!capture.CanCapture())
        {
            logger.LogError("Self-test FAILED: capture unavailable (no interactive desktop). See the reason logged above.");
            return 1;
        }

        var active = activeWindow.GetActiveApplication();
        logger.LogInformation("Foreground: process={Process} title=\"{Title}\".",
            active?.ProcessName ?? "(none)", active?.WindowTitle ?? string.Empty);

        var captures = capture.CaptureAll();
        if (captures.Count == 0)
        {
            logger.LogError("Self-test FAILED: 0 monitors captured.");
            return 1;
        }

        var sessionId = Guid.NewGuid().ToString("N");
        var capturedAt = DateTimeOffset.UtcNow;
        var written = 0;

        foreach (var monitor in captures)
        {
            using (monitor)
            {
                var metadata = processing.Process(monitor, active?.ProcessName, active?.WindowTitle, sessionId, capturedAt);

                if (metadata is null)
                {
                    logger.LogInformation("Monitor {Monitor}: unchanged (deduplicated).", monitor.MonitorNumber);
                    continue;
                }

                written++;
                logger.LogInformation(
                    "Monitor {Monitor}: {Width}x{Height}, {Size} bytes, hash={Hash}, file={File}.",
                    metadata.MonitorNumber, metadata.Width, metadata.Height, metadata.FileSize,
                    metadata.ImageHash[..12], metadata.LocalPath);
            }
        }

        logger.LogInformation("Self-test OK: {Count} monitor(s) captured, {Written} file(s) written.", captures.Count, written);
        return 0;
    }
}
