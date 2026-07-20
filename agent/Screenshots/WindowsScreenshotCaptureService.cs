using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Captures the desktop with GDI (<c>BitBlt</c> via <see cref="Graphics.CopyFromScreen(int,int,int,int,Size)"/>).
/// Monitors are enumerated with <c>EnumDisplayMonitors</c> so each is captured
/// separately at its native resolution. The process is marked per-monitor
/// DPI-aware at construction so high-DPI displays are captured at full pixel
/// dimensions (no OS downscaling).
///
/// The Windows secure desktop (UAC prompt, Ctrl+Alt+Del, login and lock screens)
/// is never captured: <see cref="CanCapture"/> returns false unless the current
/// input desktop is "Default". This also naturally skips the locked state.
///
/// Privacy: this reads the visible desktop image only. It never reads keyboard,
/// mouse, clipboard or file contents.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsScreenshotCaptureService : IScreenshotCaptureService
{
    private readonly ILogger<WindowsScreenshotCaptureService> _logger;
    private readonly ScreenshotOptions _options;

    public WindowsScreenshotCaptureService(
        ILogger<WindowsScreenshotCaptureService> logger,
        IOptions<ScreenshotOptions> options)
    {
        _logger = logger;
        _options = options.Value;

        // Per-monitor-v2 DPI awareness → CopyFromScreen returns physical pixels.
        try
        {
            SetProcessDpiAwarenessContext(DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2);
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Could not set per-monitor DPI awareness; captures may be virtualized.");
        }
    }

    // ---- Native interop ----------------------------------------------------

    private const int SM_CXSCREEN = 0;
    private const int SM_CYSCREEN = 1;
    private static readonly IntPtr DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2 = new(-4);

    [StructLayout(LayoutKind.Sequential)]
    private struct RECT
    {
        public int Left;
        public int Top;
        public int Right;
        public int Bottom;

        public int Width => Right - Left;

        public int Height => Bottom - Top;
    }

    private delegate bool MonitorEnumProc(IntPtr hMonitor, IntPtr hdc, ref RECT lprcMonitor, IntPtr dwData);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool EnumDisplayMonitors(IntPtr hdc, IntPtr lprcClip, MonitorEnumProc lpfnEnum, IntPtr dwData);

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int nIndex);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool SetProcessDpiAwarenessContext(IntPtr value);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern IntPtr OpenInputDesktop(uint dwFlags, bool fInherit, uint dwDesiredAccess);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetUserObjectInformation(IntPtr hObj, int nIndex, byte[]? pvInfo, uint nLength, out uint lpnLengthNeeded);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseDesktop(IntPtr hDesktop);

    private const int UOI_NAME = 2;
    private const uint DESKTOP_READOBJECTS = 0x0001;

    // ---- API ---------------------------------------------------------------

    public bool CanCapture()
    {
        var hDesktop = OpenInputDesktop(0, false, DESKTOP_READOBJECTS);
        if (hDesktop == IntPtr.Zero)
        {
            // No interactive input desktop we can read → secure desktop / session 0.
            return false;
        }

        try
        {
            return string.Equals(ReadDesktopName(hDesktop), "Default", StringComparison.OrdinalIgnoreCase);
        }
        finally
        {
            CloseDesktop(hDesktop);
        }
    }

    public IReadOnlyList<MonitorCapture> CaptureAll()
    {
        if (!CanCapture())
        {
            _logger.LogDebug("Skipping capture: current input desktop is not the interactive desktop.");
            return [];
        }

        List<RECT> rects = _options.MultiMonitor
            ? EnumerateMonitors()
            : new List<RECT> { PrimaryMonitorRect() };
        var captures = new List<MonitorCapture>(rects.Count);

        for (var i = 0; i < rects.Count; i++)
        {
            try
            {
                captures.Add(new MonitorCapture(i, CaptureRect(rects[i])));
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Failed to capture monitor {Monitor}.", i);
            }
        }

        return captures;
    }

    // ---- Internals ---------------------------------------------------------

    private static Bitmap CaptureRect(RECT rect)
    {
        var width = Math.Max(1, rect.Width);
        var height = Math.Max(1, rect.Height);

        var bitmap = new Bitmap(width, height, PixelFormat.Format24bppRgb);
        using var graphics = Graphics.FromImage(bitmap);
        graphics.CopyFromScreen(rect.Left, rect.Top, 0, 0, new Size(width, height), CopyPixelOperation.SourceCopy);

        return bitmap;
    }

    private static List<RECT> EnumerateMonitors()
    {
        var monitors = new List<RECT>();

        EnumDisplayMonitors(IntPtr.Zero, IntPtr.Zero, (IntPtr _, IntPtr _, ref RECT rect, IntPtr _) =>
        {
            monitors.Add(rect);
            return true;
        }, IntPtr.Zero);

        return monitors.Count > 0 ? monitors : new List<RECT> { PrimaryMonitorRect() };
    }

    private static RECT PrimaryMonitorRect() => new()
    {
        Left = 0,
        Top = 0,
        Right = GetSystemMetrics(SM_CXSCREEN),
        Bottom = GetSystemMetrics(SM_CYSCREEN),
    };

    private static string ReadDesktopName(IntPtr hDesktop)
    {
        GetUserObjectInformation(hDesktop, UOI_NAME, null, 0, out var needed);
        if (needed == 0)
        {
            return string.Empty;
        }

        var buffer = new byte[needed];
        if (!GetUserObjectInformation(hDesktop, UOI_NAME, buffer, needed, out _))
        {
            return string.Empty;
        }

        // Null-terminated Unicode string.
        return System.Text.Encoding.Unicode.GetString(buffer).TrimEnd('\0');
    }
}
