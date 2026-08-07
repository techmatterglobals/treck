namespace Treck.Agent.Applications;

/// <summary>
/// Reads the current foreground application's metadata. Implementations query
/// the OS on demand (cheap, called only when a change notification fires — never
/// in a busy loop). Returns null when there is no eligible foreground window.
/// </summary>
public interface IActiveWindowService
{
    ApplicationInfo? GetActiveApplication();
}
