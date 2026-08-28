using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Applications;

/// <summary>
/// Configuration for application-usage tracking, bound from the
/// "ApplicationTracking" section of appsettings.json.
/// </summary>
public sealed class ApplicationTrackingOptions
{
    public const string SectionName = "ApplicationTracking";

    /// <summary>Master switch. When false the tracker is never started.</summary>
    public bool Enabled { get; set; } = true;

    /// <summary>
    /// Executable file names (case-insensitive) to ignore — shell/system
    /// surfaces that are not real "applications".
    /// </summary>
    public string[] IgnoredExecutables { get; set; } =
    [
        "explorer.exe",
        "SearchHost.exe",
        "SearchApp.exe",
        "LockApp.exe",
        "ShellExperienceHost.exe",
        "StartMenuExperienceHost.exe",
        "TextInputHost.exe",
    ];

    /// <summary>Process names (case-insensitive) to ignore.</summary>
    public string[] IgnoredProcessNames { get; set; } =
    [
        "System",
        "Idle",
    ];

    /// <summary>
    /// Sessions shorter than this are discarded as noise (rapid Alt-Tab flicker).
    /// Set to 0 to keep every session.
    /// </summary>
    [Range(0, 3600)]
    public int MinimumSessionSeconds { get; set; } = 1;

    /// <summary>Window titles are truncated to this many characters before upload.</summary>
    [Range(0, 1024)]
    public int MaxWindowTitleLength { get; set; } = 255;
}
