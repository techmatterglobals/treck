using System.Diagnostics;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Spooling;
using Treck.Agent.Storage;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Runs in the Session-0 Windows service and keeps a screenshot capture helper
/// alive in the active interactive console session (where the desktop is
/// actually visible). It:
///
///   - launches <c>TreckAgent.exe --capture-helper</c> into the active session;
///   - relaunches it if it exits (crash / user log-off→on);
///   - relaunches it in the new session on fast-user-switch / session change;
///   - terminates it on service shutdown.
///
/// The helper writes captures to the shared spool; the service's SyncWorker
/// uploads them. When there is no interactive session (login screen, all users
/// logged off) the supervisor simply waits — nothing to capture.
/// </summary>
public sealed class ScreenshotHelperSupervisor : BackgroundService
{
    private const string HelperArgument = "--capture-helper";
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(10);
    private const uint INVALID_SESSION = 0xFFFFFFFF;

    private readonly ILogger<ScreenshotHelperSupervisor> _logger;
    private readonly ScreenshotOptions _options;
    private readonly IInteractiveSessionLauncher _launcher;
    private readonly IStoragePathProvider _paths;

    private ILaunchedProcess? _helper;

    public ScreenshotHelperSupervisor(
        ILogger<ScreenshotHelperSupervisor> logger,
        IOptions<ScreenshotOptions> options,
        IInteractiveSessionLauncher launcher,
        IStoragePathProvider paths)
    {
        _logger = logger;
        _options = options.Value;
        _launcher = launcher;
        _paths = paths;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        if (!_options.Enabled)
        {
            _logger.LogInformation("Screenshot capture is disabled; helper supervisor idle.");
            return;
        }

        _logger.LogInformation("Screenshot helper supervisor started (session-0 → interactive launch).");

        // The helper runs as the interactive user but the helper directory is
        // created by this LocalSystem service; grant the interactive user write so
        // the helper can spool captures/events. Scope the grant to the helper
        // subtree only — the offline queue and encrypted device token stay untouched.
        GrantInteractiveAccessToHelperDir();

        try
        {
            using var timer = new PeriodicTimer(PollInterval);
            do
            {
                try
                {
                    EnsureHelper();
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Helper supervision cycle failed.");
                }
            }
            while (await timer.WaitForNextTickAsync(stoppingToken));
        }
        catch (OperationCanceledException)
        {
            // Expected on shutdown.
        }
        finally
        {
            StopHelper();
        }

        _logger.LogInformation("Screenshot helper supervisor stopped.");
    }

    private void EnsureHelper()
    {
        var activeSession = _launcher.ActiveConsoleSessionId;

        // No interactive session (login screen / all logged off): nothing to do.
        if (activeSession == INVALID_SESSION)
        {
            if (_helper is not null)
            {
                _logger.LogInformation("Interactive session ended; stopping capture helper.");
                StopHelper();
            }

            return;
        }

        // Helper died, or the active session changed (fast-user-switch): relaunch.
        if (_helper is not null && (!_helper.IsRunning || _helper.SessionId != activeSession))
        {
            _logger.LogInformation(
                "Capture helper needs relaunch (running={Running}, helperSession={HelperSession}, activeSession={ActiveSession}).",
                _helper.IsRunning, _helper.SessionId, activeSession);
            StopHelper();
        }

        _helper ??= _launcher.Launch(ExecutablePath(), HelperArgument);
    }

    private void StopHelper()
    {
        if (_helper is null)
        {
            return;
        }

        try
        {
            if (_helper.IsRunning)
            {
                _helper.Terminate();
            }
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Error terminating capture helper.");
        }
        finally
        {
            _helper.Dispose();
            _helper = null;
        }
    }

    private static string ExecutablePath()
        => Environment.ProcessPath ?? Process.GetCurrentProcess().MainModule!.FileName;

    /// <summary>
    /// Best-effort: grant BUILTIN\Users Modify on the helper directory via icacls,
    /// so the interactive helper can write image temp files and spool sidecars
    /// there. Failure is non-fatal (logged); the helper will simply be unable to
    /// spool until an administrator grants access.
    /// </summary>
    private void GrantInteractiveAccessToHelperDir()
    {
        var directory = HelperPaths.Root(_paths);

        try
        {
            Directory.CreateDirectory(directory);
            Directory.CreateDirectory(HelperPaths.Screenshots(_paths));
            Directory.CreateDirectory(HelperPaths.Spool(_paths));

            // *S-1-5-32-545 = BUILTIN\Users; (OI)(CI) = inherit to files/subdirs; M = Modify.
            var startInfo = new ProcessStartInfo("icacls")
            {
                ArgumentList = { directory, "/grant", "*S-1-5-32-545:(OI)(CI)M", "/T", "/C", "/Q" },
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };

            using var process = Process.Start(startInfo);
            process?.WaitForExit(10_000);

            if (process is { ExitCode: 0 })
            {
                _logger.LogInformation("Granted interactive users write access to {Dir}.", directory);
            }
            else
            {
                _logger.LogWarning("icacls grant on {Dir} returned exit code {Code}.", directory, process?.ExitCode);
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Could not grant interactive access to {Dir}; the helper may fail to spool.", directory);
        }
    }
}
