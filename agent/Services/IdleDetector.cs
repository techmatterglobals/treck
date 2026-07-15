using System.Runtime.InteropServices;

namespace Treck.Agent.Services;

/// <summary>
/// Measures how long the machine has been idle (no keyboard/mouse input) using
/// the Win32 GetLastInputInfo API.
///
/// IMPORTANT: this reflects input for the *session the caller runs in*. Deploy
/// this in the interactive user-session helper, not session 0 (see doc 17).
/// </summary>
public sealed class IdleDetector
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

    /// <summary>Seconds since the last keyboard/mouse input (0 on failure).</summary>
    public int GetIdleSeconds()
    {
        var info = new LASTINPUTINFO { cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>() };

        if (!GetLastInputInfo(ref info))
        {
            return 0;
        }

        // Environment.TickCount and dwTime share the same tick base. Unsigned
        // subtraction handles the ~49-day wrap correctly.
        uint idleMs = unchecked((uint)Environment.TickCount - info.dwTime);

        return (int)(idleMs / 1000u);
    }
}
