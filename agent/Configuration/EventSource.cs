namespace Treck.Agent.Configuration;

/// <summary>
/// Identifies WHERE an event was collected, stamped onto every forwarded event
/// for backend debugging (Phase 8 #3). It makes it obvious in the server whether
/// an event came from the correct place — the interactive helper (session 1+) or
/// the service (session 0) — rather than silently from the wrong session.
/// </summary>
/// <param name="SessionId">Windows session the collector runs in (0 = service).</param>
/// <param name="User">User the collector runs as.</param>
/// <param name="Process">Process label, e.g. "TreckAgent(helper)".</param>
/// <param name="CollectionMode">"InteractiveHelper" or "Service".</param>
public sealed record EventSource(
    int SessionId,
    string User,
    string Process,
    string CollectionMode)
{
    public const string InteractiveHelper = "InteractiveHelper";
    public const string Service = "Service";

    /// <summary>Build from the current process, with the given collection mode/label.</summary>
    public static EventSource Current(string collectionMode, string processLabel)
    {
        var sessionId = 0;
        try
        {
            sessionId = System.Diagnostics.Process.GetCurrentProcess().SessionId;
        }
        catch
        {
            // Fall back to 0 if the session id cannot be read.
        }

        return new EventSource(sessionId, Environment.UserName, processLabel, collectionMode);
    }
}
