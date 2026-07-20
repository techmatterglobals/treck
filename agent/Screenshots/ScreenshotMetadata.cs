namespace Treck.Agent.Screenshots;

/// <summary>
/// Metadata for one captured screenshot (Phase 8). Serialized (PascalCase) as
/// the offline-queue payload for a <c>Screenshot</c> event; the compressed image
/// bytes live in a local temp file at <see cref="LocalPath"/> until the sync
/// pipeline uploads them and deletes the file.
///
/// The server's StoreScreenshotRequest reads CapturedAt / MonitorNumber /
/// Width / Height / ImageHash / ActiveProcess / ActiveWindowTitle / SessionId
/// as multipart fields (LocalPath and Format never leave the device).
/// </summary>
public sealed record ScreenshotMetadata(
    DateTimeOffset CapturedAt,
    int MonitorNumber,
    int Width,
    int Height,
    long FileSize,
    string ImageHash,
    string? ActiveProcess,
    string? ActiveWindowTitle,
    string SessionId,
    string LocalPath,
    string Format);
