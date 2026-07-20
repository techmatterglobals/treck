using Microsoft.Extensions.Logging;
using Treck.Agent.Api;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Screenshot upload step of the sync pipeline (Phase 8). Reads the compressed
/// temp file, uploads it via <see cref="ITreckApiClient.UploadScreenshotAsync"/>,
/// and — only on success — deletes the temp file (the offline-queue row is then
/// marked synced by the caller). Preserves the existing retry semantics: a false
/// return keeps the item queued; a 401 bubbles up so the device re-registers.
/// </summary>
public sealed class ScreenshotSyncService : IScreenshotSyncService
{
    private readonly ITreckApiClient _api;
    private readonly ILogger<ScreenshotSyncService> _logger;

    public ScreenshotSyncService(ITreckApiClient api, ILogger<ScreenshotSyncService> logger)
    {
        _api = api;
        _logger = logger;
    }

    public async Task<bool> UploadAsync(string bearerToken, ScreenshotMetadata metadata, CancellationToken cancellationToken)
    {
        if (!File.Exists(metadata.LocalPath))
        {
            // The temp file is gone (already pruned / manual cleanup). Drop the
            // queued item rather than retrying forever.
            _logger.LogWarning("Screenshot temp file missing ({Path}); dropping queued item.", metadata.LocalPath);
            return true;
        }

        byte[] bytes;
        try
        {
            bytes = await File.ReadAllBytesAsync(metadata.LocalPath, cancellationToken);
        }
        catch (IOException ex)
        {
            _logger.LogWarning(ex, "Could not read screenshot temp file {Path}; will retry.", metadata.LocalPath);
            return false;
        }

        var uploaded = await _api.UploadScreenshotAsync(bearerToken, metadata, bytes, cancellationToken);

        if (uploaded)
        {
            TryDeleteTempFile(metadata.LocalPath);
        }

        return uploaded;
    }

    private void TryDeleteTempFile(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception ex)
        {
            // Non-fatal: retention/cleanup will catch it; the upload still counts.
            _logger.LogDebug(ex, "Could not delete screenshot temp file {Path}.", path);
        }
    }
}
