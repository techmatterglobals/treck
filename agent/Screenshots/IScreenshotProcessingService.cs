namespace Treck.Agent.Screenshots;

/// <summary>
/// Compresses a captured monitor bitmap, hashes it, and writes it to a local
/// temp file, returning the metadata to enqueue. Returns null when the capture
/// duplicates the immediately-preceding one for the same monitor (unchanged
/// screen), so identical frames are never queued or uploaded.
/// </summary>
public interface IScreenshotProcessingService
{
    ScreenshotMetadata? Process(
        MonitorCapture capture,
        string? activeProcess,
        string? activeWindowTitle,
        string sessionId,
        DateTimeOffset capturedAt);
}
