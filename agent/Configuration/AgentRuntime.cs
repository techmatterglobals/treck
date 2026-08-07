namespace Treck.Agent.Configuration;

/// <summary>
/// Runtime topology flags resolved once at startup (Phase 8). They tell the main
/// <see cref="Worker"/> whether interactive-session collection (heartbeat/idle
/// and application-usage tracking) runs in-process, or has been delegated to the
/// interactive capture helper because this process is the Session-0 service.
/// </summary>
public sealed class AgentRuntime
{
    /// <summary>
    /// True when the Worker should run heartbeat + application-usage collection
    /// itself (console/dev, already interactive). False when a Windows service in
    /// session 0 has handed that work to the interactive helper — the Worker then
    /// runs only the session monitor + registration.
    /// </summary>
    public bool CollectInteractiveInProcess { get; init; } = true;
}
