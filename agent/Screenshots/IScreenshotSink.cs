namespace Treck.Agent.Screenshots;

/// <summary>
/// Destination for a completed capture. Decouples <see cref="ScreenshotWorker"/>
/// from *how* the capture reaches the upload pipeline:
///
/// <list type="bullet">
///   <item><see cref="OfflineQueueScreenshotSink"/> — enqueue straight into the
///     offline SQLite queue (used when capture runs in the same process as the
///     sync loop, i.e. console/dev mode).</item>
///   <item><see cref="SpoolScreenshotSink"/> — write a spool sidecar for the
///     Session-0 service to pick up (used by the interactive capture helper, so
///     the service stays the single owner of the offline queue).</item>
/// </list>
/// </summary>
public interface IScreenshotSink
{
    void Submit(ScreenshotMetadata metadata);
}
