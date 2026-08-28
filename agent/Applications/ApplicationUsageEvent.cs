namespace Treck.Agent.Applications;

/// <summary>
/// A COMPLETED application-usage session — the only thing ever transmitted
/// (per-second sampling is never sent). Serialized with PascalCase keys and
/// enqueued as an <c>app_usage</c> offline event; the server's
/// ApplicationUsageProjector reads these keys case-tolerantly and stores one row
/// per (computer, SessionId).
/// </summary>
/// <param name="SessionId">Stable GUID for this session (server idempotency key).</param>
/// <param name="ProcessName">Friendly process name.</param>
/// <param name="ExecutableName">Executable file name, if known.</param>
/// <param name="WindowTitle">Window title (sanitized + length-bounded).</param>
/// <param name="ProcessId">OS process id.</param>
/// <param name="StartedAt">When the session began.</param>
/// <param name="EndedAt">When the session ended (app/title change, lock, or stop).</param>
/// <param name="DurationSeconds">Whole seconds the session lasted.</param>
/// <param name="UserSession">Interactive Windows session id.</param>
/// <param name="IsSystemProcess">True for shell/system-owned windows.</param>
public sealed record ApplicationUsageEvent(
    string SessionId,
    string ProcessName,
    string? ExecutableName,
    string WindowTitle,
    int ProcessId,
    DateTimeOffset StartedAt,
    DateTimeOffset EndedAt,
    int DurationSeconds,
    int UserSession,
    bool IsSystemProcess);
