namespace Treck.Agent.Downloads;

/// <summary>
/// Metadata for one completed download (Phase 12). Serialized as the payload of
/// a <c>file_download</c> offline event (PascalCase keys, matching the server's
/// case-tolerant projector). This is metadata ONLY — never the file's contents.
/// </summary>
/// <param name="FileName">File name including extension (no path).</param>
/// <param name="FileExtension">Lower-case extension without the dot (may be empty).</param>
/// <param name="FileSize">Size in bytes at the moment of detection.</param>
/// <param name="LocalPath">Full local path on the workstation.</param>
/// <param name="DownloadFolder">The folder the file landed in.</param>
/// <param name="Sha256Hash">Hex SHA-256, or null when hashing is disabled/skipped.</param>
/// <param name="DownloadedAt">UTC timestamp the file was observed as complete.</param>
/// <param name="ApplicationName">Foreground application at detection time, if known.</param>
/// <param name="ProcessName">Foreground process name, if known.</param>
/// <param name="WindowTitle">Foreground window title (sanitized), if known.</param>
public sealed record DownloadedFile(
    string FileName,
    string FileExtension,
    long FileSize,
    string LocalPath,
    string DownloadFolder,
    string? Sha256Hash,
    DateTimeOffset DownloadedAt,
    string? ApplicationName,
    string? ProcessName,
    string? WindowTitle);
