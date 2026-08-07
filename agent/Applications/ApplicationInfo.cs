namespace Treck.Agent.Applications;

/// <summary>
/// A point-in-time snapshot of the foreground application. This is metadata
/// ONLY — process/window identity — never any user input, clipboard, screen or
/// file contents (see doc 26, "Privacy").
/// </summary>
/// <param name="ProcessName">Friendly process name (e.g. "Visual Studio Code").</param>
/// <param name="ExecutableName">Executable file name (e.g. "Code.exe"), if known.</param>
/// <param name="WindowTitle">Foreground window title (sanitized), may be empty.</param>
/// <param name="ProcessId">OS process id of the foreground window's owner.</param>
/// <param name="IsSystemProcess">True for shell/system-owned windows.</param>
public sealed record ApplicationInfo(
    string ProcessName,
    string? ExecutableName,
    string WindowTitle,
    int ProcessId,
    bool IsSystemProcess)
{
    /// <summary>
    /// Session identity key: a session is one contiguous period on the SAME
    /// process AND the SAME window title. A window-title change (even within the
    /// same process) ends the previous session and starts a new one.
    /// </summary>
    public string SessionKey => $"{ProcessName}{ExecutableName}{WindowTitle}";
}
