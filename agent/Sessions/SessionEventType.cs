namespace Treck.Agent.Sessions;

/// <summary>The kinds of Windows session transitions the agent detects.</summary>
public enum SessionEventType
{
    Unknown = 0,
    Logon,
    Logoff,
    Lock,
    Unlock,
    Shutdown,
    Restart,
}
