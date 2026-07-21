using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Offline;
using Treck.Agent.Storage;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Service-side bridge (Session 0): ingests spool sidecars written by the
/// interactive capture helper into the offline SQLite queue as <c>Screenshot</c>
/// events, then deletes the sidecar. Keeping this the ONLY writer of the queue
/// avoids cross-process database contention; the existing SyncWorker then uploads
/// and <see cref="ScreenshotSyncService"/> deletes the referenced temp image.
///
/// Polls (rather than FileSystemWatcher) so it is simple and resilient to missed
/// events; the cadence is a fraction of the capture interval.
/// </summary>
public sealed class ScreenshotSpoolWorker : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(5);

    private readonly ILogger<ScreenshotSpoolWorker> _logger;
    private readonly ScreenshotOptions _options;
    private readonly IOfflineEventStore _eventStore;
    private readonly string _spoolDirectory;

    public ScreenshotSpoolWorker(
        ILogger<ScreenshotSpoolWorker> logger,
        IOptions<ScreenshotOptions> options,
        IOfflineEventStore eventStore,
        IStoragePathProvider paths)
    {
        _logger = logger;
        _options = options.Value;
        _eventStore = eventStore;
        _spoolDirectory = ScreenshotSpool.SpoolDirectory(paths);
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        if (!_options.Enabled)
        {
            return;
        }

        Directory.CreateDirectory(_spoolDirectory);
        _logger.LogInformation("Screenshot spool worker started (dir={Dir}).", _spoolDirectory);

        try
        {
            using var timer = new PeriodicTimer(PollInterval);
            do
            {
                try
                {
                    IngestOnce();
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Screenshot spool ingest cycle failed.");
                }
            }
            while (await timer.WaitForNextTickAsync(stoppingToken));
        }
        catch (OperationCanceledException)
        {
            // Expected on shutdown.
        }

        _logger.LogInformation("Screenshot spool worker stopped.");
    }

    private void IngestOnce()
    {
        // Oldest first, so upload order matches capture order.
        var sidecars = Directory.EnumerateFiles(_spoolDirectory, "*.json")
            .OrderBy(File.GetCreationTimeUtc)
            .ToList();

        foreach (var sidecar in sidecars)
        {
            ScreenshotMetadata? metadata;
            try
            {
                metadata = JsonSerializer.Deserialize<ScreenshotMetadata>(File.ReadAllText(sidecar));
            }
            catch (Exception ex)
            {
                // Corrupt/partial sidecar: quarantine by deleting so it can't wedge.
                _logger.LogWarning(ex, "Discarding unreadable spool sidecar {File}.", sidecar);
                TryDelete(sidecar);
                continue;
            }

            if (metadata is null)
            {
                TryDelete(sidecar);
                continue;
            }

            var json = JsonSerializer.Serialize(metadata);
            _eventStore.Enqueue(OfflineEvent.Create(OfflineEventKind.Screenshot, json, metadata.CapturedAt));

            _logger.LogInformation(
                "Screenshot ingested from spool: monitor={Monitor} {Width}x{Height} {Size}B.",
                metadata.MonitorNumber, metadata.Width, metadata.Height, metadata.FileSize);

            TryDelete(sidecar); // the image temp file is deleted after upload
        }
    }

    private void TryDelete(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Could not delete spool sidecar {File}.", path);
        }
    }
}
