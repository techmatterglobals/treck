using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Storage;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Writes a completed capture as a spool sidecar (one JSON file per capture) for
/// the Session-0 service to ingest. Used by the interactive capture helper so the
/// service remains the single writer of the offline SQLite queue (no cross-process
/// database contention). The sidecar is written atomically (temp file + rename)
/// so the service never reads a half-written file.
/// </summary>
public sealed class SpoolScreenshotSink : IScreenshotSink
{
    private readonly ILogger<SpoolScreenshotSink> _logger;
    private readonly string _spoolDirectory;

    public SpoolScreenshotSink(ILogger<SpoolScreenshotSink> logger, IStoragePathProvider paths)
    {
        _logger = logger;
        _spoolDirectory = ScreenshotSpool.SpoolDirectory(paths);
        Directory.CreateDirectory(_spoolDirectory);
    }

    public void Submit(ScreenshotMetadata metadata)
    {
        var finalPath = Path.Combine(_spoolDirectory, $"{metadata.ImageHash}.json");
        var tempPath = finalPath + ".tmp";

        var json = JsonSerializer.Serialize(metadata);
        File.WriteAllText(tempPath, json);
        File.Move(tempPath, finalPath, overwrite: true);

        _logger.LogInformation(
            "Screenshot spooled: monitor={Monitor} {Width}x{Height} {Size}B.",
            metadata.MonitorNumber, metadata.Width, metadata.Height, metadata.FileSize);
    }
}
