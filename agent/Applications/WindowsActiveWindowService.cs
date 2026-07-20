using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using Microsoft.Extensions.Logging;

namespace Treck.Agent.Applications;

/// <summary>
/// Reads the foreground application via Win32:
/// <c>GetForegroundWindow</c> → <c>GetWindowThreadProcessId</c> →
/// <c>GetWindowText</c> plus the managed <see cref="Process"/> for the friendly
/// name and executable. Called only when a WinEvent fires (never in a loop).
///
/// Privacy: this reads window/process identity ONLY — the title text is
/// sanitized (control characters stripped) before it leaves this class. No
/// keyboard, mouse, clipboard, screen or file contents are ever read.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsActiveWindowService : IActiveWindowService
{
    private readonly ILogger<WindowsActiveWindowService> _logger;

    public WindowsActiveWindowService(ILogger<WindowsActiveWindowService> logger)
    {
        _logger = logger;
    }

    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);

    [DllImport("user32.dll")]
    private static extern int GetWindowTextLength(IntPtr hWnd);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId);

    public ApplicationInfo? GetActiveApplication()
    {
        var hWnd = GetForegroundWindow();
        if (hWnd == IntPtr.Zero)
        {
            return null; // No foreground window (e.g. locked desktop).
        }

        if (GetWindowThreadProcessId(hWnd, out var pid) == 0 || pid == 0)
        {
            return null;
        }

        var title = Sanitize(ReadWindowText(hWnd));

        string processName;
        string? executable = null;
        var isSystem = pid <= 4; // 0 = Idle, 4 = System.

        try
        {
            using var process = Process.GetProcessById((int)pid);
            processName = process.ProcessName;

            try
            {
                var module = process.MainModule?.ModuleName;
                if (!string.IsNullOrEmpty(module))
                {
                    executable = module;
                }
            }
            catch
            {
                // MainModule throws for protected/elevated processes — the
                // friendly name is enough; the executable stays null.
            }
        }
        catch (Exception ex)
        {
            // The process may have exited between the two calls.
            _logger.LogDebug(ex, "Could not resolve process {Pid} for the foreground window.", pid);
            return null;
        }

        return new ApplicationInfo(
            ProcessName: processName,
            ExecutableName: executable,
            WindowTitle: title,
            ProcessId: (int)pid,
            IsSystemProcess: isSystem);
    }

    private static string ReadWindowText(IntPtr hWnd)
    {
        var length = GetWindowTextLength(hWnd);
        if (length <= 0)
        {
            return string.Empty;
        }

        var buffer = new StringBuilder(length + 1);
        var copied = GetWindowText(hWnd, buffer, buffer.Capacity);

        return copied > 0 ? buffer.ToString() : string.Empty;
    }

    /// <summary>Strip control characters and trim (defence-in-depth; the server sanitizes too).</summary>
    private static string Sanitize(string value)
    {
        if (string.IsNullOrEmpty(value))
        {
            return string.Empty;
        }

        var builder = new StringBuilder(value.Length);
        foreach (var ch in value)
        {
            if (!char.IsControl(ch))
            {
                builder.Append(ch);
            }
        }

        return builder.ToString().Trim();
    }
}
