using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Offline;

/// <summary>Configuration for the offline queue and its sync worker.</summary>
public sealed class OfflineStoreOptions
{
    public const string SectionName = "OfflineStore";

    /// <summary>Base delay between sync cycles (seconds). Backoff multiplies this.</summary>
    [Range(5, 3600)]
    public int SyncIntervalSeconds { get; set; } = 30;

    /// <summary>Upper bound for exponential backoff between failed cycles (seconds).</summary>
    [Range(30, 86400)]
    public int MaxBackoffSeconds { get; set; } = 900;

    /// <summary>Max events pulled/attempted per sync cycle.</summary>
    [Range(1, 1000)]
    public int BatchSize { get; set; } = 100;

    /// <summary>Hard cap on total stored rows (size limit). Oldest rows are dropped past this.</summary>
    [Range(1000, 5000000)]
    public int MaxRows { get; set; } = 100000;

    /// <summary>Synced rows older than this are cleaned up (hours).</summary>
    [Range(1, 8760)]
    public int RetentionHours { get; set; } = 24;
}
