using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Microsoft.Extensions.Logging;

namespace Treck.Agent.Applications;

/// <summary>
/// Event-driven foreground watcher using Win32 <c>SetWinEventHook</c>. It hooks
/// two out-of-context events on the interactive desktop:
///
/// <list type="bullet">
///   <item><c>EVENT_SYSTEM_FOREGROUND</c> — a different window came to front;</item>
///   <item><c>EVENT_OBJECT_NAMECHANGE</c> — the foreground window's title changed
///         (e.g. switching browser tabs).</item>
/// </list>
///
/// Both fire immediately, so there is NO polling and idle CPU is negligible.
/// WinEvent hooks require a running message loop, so the hook is installed on a
/// dedicated STA-style thread that pumps messages until <see cref="Stop"/>.
/// Each notification reads the foreground app via <see cref="IActiveWindowService"/>
/// and raises <see cref="ApplicationChanged"/>; the state machine
/// (<see cref="ApplicationSessionManager"/>) decides what constitutes a session.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsApplicationTracker : IApplicationTracker
{
    private const uint EVENT_SYSTEM_FOREGROUND = 0x0003;
    private const uint EVENT_OBJECT_NAMECHANGE = 0x800C;
    private const uint WINEVENT_OUTOFCONTEXT = 0x0000;
    private const uint WINEVENT_SKIPOWNPROCESS = 0x0002;
    private const int OBJID_WINDOW = 0;

    private readonly ILogger<WindowsApplicationTracker> _logger;
    private readonly IActiveWindowService _activeWindow;
    private readonly TimeProvider _timeProvider;
    private readonly object _gate = new();

    // Keep the delegate alive for the lifetime of the hook (else it is GC'd and
    // the native callback faults).
    private WinEventDelegate? _callback;
    private Thread? _pumpThread;
    private uint _pumpThreadId;
    private bool _started;

    public event EventHandler<ApplicationChangedEventArgs>? ApplicationChanged;

    public WindowsApplicationTracker(
        ILogger<WindowsApplicationTracker> logger,
        IActiveWindowService activeWindow,
        TimeProvider timeProvider)
    {
        _logger = logger;
        _activeWindow = activeWindow;
        _timeProvider = timeProvider;
    }

    private delegate void WinEventDelegate(
        IntPtr hWinEventHook, uint eventType, IntPtr hWnd,
        int idObject, int idChild, uint dwEventThread, uint dwmsEventTime);

    [DllImport("user32.dll")]
    private static extern IntPtr SetWinEventHook(
        uint eventMin, uint eventMax, IntPtr hmodWinEventProc,
        WinEventDelegate lpfnWinEventProc, uint idProcess, uint idThread, uint dwFlags);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool UnhookWinEvent(IntPtr hWinEventHook);

    [DllImport("user32.dll")]
    private static extern int GetMessage(out MSG lpMsg, IntPtr hWnd, uint wMsgFilterMin, uint wMsgFilterMax);

    [DllImport("user32.dll")]
    private static extern IntPtr DispatchMessage(ref MSG lpMsg);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool TranslateMessage(ref MSG lpMsg);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool PostThreadMessage(uint idThread, uint msg, IntPtr wParam, IntPtr lParam);

    [DllImport("kernel32.dll")]
    private static extern uint GetCurrentThreadId();

    private const uint WM_QUIT = 0x0012;

    [StructLayout(LayoutKind.Sequential)]
    private struct MSG
    {
        public IntPtr hwnd;
        public uint message;
        public IntPtr wParam;
        public IntPtr lParam;
        public uint time;
        public int ptX;
        public int ptY;
    }

    public void Start()
    {
        lock (_gate)
        {
            if (_started)
            {
                return;
            }

            _started = true;
            _pumpThread = new Thread(RunMessageLoop)
            {
                IsBackground = true,
                Name = "TreckAppTracker",
            };
            _pumpThread.SetApartmentState(ApartmentState.STA);
            _pumpThread.Start();
        }

        _logger.LogInformation("Application tracker started (WinEvent hooks; no polling).");

        // Emit the current foreground app so a session opens immediately.
        RaiseCurrent();
    }

    public void Stop()
    {
        Thread? thread;

        lock (_gate)
        {
            if (!_started)
            {
                return;
            }

            _started = false;
            thread = _pumpThread;
            _pumpThread = null;

            if (_pumpThreadId != 0)
            {
                PostThreadMessage(_pumpThreadId, WM_QUIT, IntPtr.Zero, IntPtr.Zero);
            }
        }

        thread?.Join(TimeSpan.FromSeconds(5));
        _logger.LogInformation("Application tracker stopped.");
    }

    private void RunMessageLoop()
    {
        _pumpThreadId = GetCurrentThreadId();
        _callback = OnWinEvent;

        var foregroundHook = SetWinEventHook(
            EVENT_SYSTEM_FOREGROUND, EVENT_SYSTEM_FOREGROUND, IntPtr.Zero,
            _callback, 0, 0, WINEVENT_OUTOFCONTEXT | WINEVENT_SKIPOWNPROCESS);

        var nameHook = SetWinEventHook(
            EVENT_OBJECT_NAMECHANGE, EVENT_OBJECT_NAMECHANGE, IntPtr.Zero,
            _callback, 0, 0, WINEVENT_OUTOFCONTEXT | WINEVENT_SKIPOWNPROCESS);

        if (foregroundHook == IntPtr.Zero && nameHook == IntPtr.Zero)
        {
            _logger.LogError("Failed to install WinEvent hooks; application tracking is inactive.");
            return;
        }

        try
        {
            // Standard message pump; GetMessage blocks (no busy-wait) until a
            // hooked event or WM_QUIT arrives.
            while (GetMessage(out var msg, IntPtr.Zero, 0, 0) > 0)
            {
                TranslateMessage(ref msg);
                DispatchMessage(ref msg);
            }
        }
        finally
        {
            if (foregroundHook != IntPtr.Zero)
            {
                UnhookWinEvent(foregroundHook);
            }

            if (nameHook != IntPtr.Zero)
            {
                UnhookWinEvent(nameHook);
            }

            _callback = null;
        }
    }

    private void OnWinEvent(
        IntPtr hWinEventHook, uint eventType, IntPtr hWnd,
        int idObject, int idChild, uint dwEventThread, uint dwmsEventTime)
    {
        // Name changes fire for many child objects; only the window itself matters.
        if (eventType == EVENT_OBJECT_NAMECHANGE && idObject != OBJID_WINDOW)
        {
            return;
        }

        RaiseCurrent();
    }

    private void RaiseCurrent()
    {
        try
        {
            var app = _activeWindow.GetActiveApplication();
            ApplicationChanged?.Invoke(this, new ApplicationChangedEventArgs(app, _timeProvider.GetUtcNow()));
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Failed to read the foreground application.");
        }
    }

    public void Dispose()
    {
        Stop();
        GC.SuppressFinalize(this);
    }
}
