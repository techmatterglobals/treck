using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Sessions;

/// <summary>Configuration for the session monitor.</summary>
public sealed class SessionMonitorOptions
{
    public const string SectionName = "SessionMonitor";

    /// <summary>
    /// Consecutive identical events within this window are treated as duplicates
    /// and dropped (Windows can emit e.g. two lock notifications in quick
    /// succession). 0 disables suppression.
    /// </summary>
    [Range(0, 60000)]
    public int DuplicateSuppressionMilliseconds { get; set; } = 1000;
}
