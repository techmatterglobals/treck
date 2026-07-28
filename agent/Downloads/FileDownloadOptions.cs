using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Downloads;

/// <summary>
/// Configuration for file-download monitoring (Phase 12), bound from the
/// "FileDownloads" section of appsettings.json. Metadata only — file contents
/// are never read or uploaded. Disabled by default so existing deployments are
/// unaffected until an administrator opts in.
/// </summary>
public sealed class FileDownloadOptions
{
    public const string SectionName = "FileDownloads";

    /// <summary>Master switch. When false the monitor is never started.</summary>
    public bool Enabled { get; set; } = false;

    /// <summary>
    /// Folders to watch. Empty → the current user's Downloads folder is used
    /// automatically. Environment variables (e.g. %USERPROFILE%) are expanded.
    /// </summary>
    public string[] WatchedFolders { get; set; } = [];

    /// <summary>Also watch each folder's subdirectories.</summary>
    public bool IncludeSubdirectories { get; set; } = true;

    /// <summary>File extensions (no dot, case-insensitive) to ignore entirely.</summary>
    public string[] IgnoredExtensions { get; set; } =
    [
        "tmp", "crdownload", "part", "partial", "download",
    ];

    /// <summary>Source applications (process names, case-insensitive) to ignore.</summary>
    public string[] IgnoredApplications { get; set; } = [];

    /// <summary>Folder path fragments (case-insensitive) to ignore.</summary>
    public string[] IgnoredFolders { get; set; } = [];

    /// <summary>Compute a SHA-256 of the completed file when true.</summary>
    public bool HashEnabled { get; set; } = false;

    /// <summary>Skip hashing for files larger than this (bytes). 0 = no limit.</summary>
    [Range(0, long.MaxValue)]
    public long MaxHashBytes { get; set; } = 52428800; // 50 MB

    /// <summary>
    /// A newly-seen file is reported only once its size has been stable for this
    /// long, so in-progress downloads (.crdownload/.part) are not reported until
    /// finished. Debounced, not polled in a tight loop.
    /// </summary>
    [Range(250, 60000)]
    public int StabilizationMilliseconds { get; set; } = 1500;
}
