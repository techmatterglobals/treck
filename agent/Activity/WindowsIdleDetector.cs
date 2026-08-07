using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace Treck.Agent.Activity;

/// <summary>
/// Idle detection via the Win32 <c>GetLastInputInfo</c> API. Reports input for
/// the session the calling process runs in, so it belongs in the interactive
/// user session (per doc 17).
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsIdleDetector : IIdleDetector
{
    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO
    {
        public uint cbSize;
        public uint dwTime;
    }

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

    public TimeSpan GetIdleTime()
    {
        var info = new LASTINPUTINFO { cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>() };

        if (!GetLastInputInfo(ref info))
        {
            return TimeSpan.Zero;
        }

        // dwTime and Environment.TickCount share the same tick base; unsigned
        // subtraction handles the ~49-day wraparound.
        uint idleMilliseconds = unchecked((uint)Environment.TickCount - info.dwTime);

        return TimeSpan.FromMilliseconds(idleMilliseconds);
    }
}
