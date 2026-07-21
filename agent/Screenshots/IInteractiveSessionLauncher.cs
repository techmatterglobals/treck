namespace Treck.Agent.Screenshots;

/// <summary>
/// Launches a process in the active interactive console session from a
/// Session-0 service, so desktop-bound work (screenshot capture) runs where it
/// can actually see the user's screen.
/// </summary>
public interface IInteractiveSessionLauncher
{
    /// <summary>The session id currently attached to the physical console, or 0xFFFFFFFF if none.</summary>
    uint ActiveConsoleSessionId { get; }

    /// <summary>
    /// Start <paramref name="executablePath"/> <paramref name="arguments"/> as the
    /// user logged into the active console session. Returns a handle to the
    /// launched process, or null when there is no interactive session or the
    /// launch failed (reason logged).
    /// </summary>
    ILaunchedProcess? Launch(string executablePath, string arguments);
}

/// <summary>A launched interactive-session process the supervisor can watch and stop.</summary>
public interface ILaunchedProcess : IDisposable
{
    uint SessionId { get; }

    bool IsRunning { get; }

    void Terminate();
}
