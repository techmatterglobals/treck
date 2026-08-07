namespace Treck.Agent.Screenshots;

/// <summary>
/// Uploads one queued screenshot: reads its temp file, POSTs it to the API, and
/// deletes the temp file after a successful upload. Invoked by the shared
/// event uploader for <c>Screenshot</c>-kind offline events, so screenshots ride
/// the same offline queue, ordering and retry/backoff as every other event.
/// </summary>
public interface IScreenshotSyncService
{
    /// <summary>
    /// Returns true when the server accepted the screenshot (2xx). On success the
    /// local temp file is deleted. A missing temp file is treated as success (the
    /// item is dropped) so the queue never wedges on a vanished file.
    /// </summary>
    Task<bool> UploadAsync(string bearerToken, ScreenshotMetadata metadata, CancellationToken cancellationToken);
}
