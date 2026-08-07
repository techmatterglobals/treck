using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Offline;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Enqueues a completed capture straight into the offline SQLite queue as a
/// <c>Screenshot</c> event. Used when capture and sync share a process (console /
/// development mode). In the Windows-service deployment the capture helper uses
/// <see cref="SpoolScreenshotSink"/> instead so only the service writes the queue.
/// </summary>
public sealed class OfflineQueueScreenshotSink : IScreenshotSink
{
    private readonly IOfflineEventStore _eventStore;
    private readonly ILogger<OfflineQueueScreenshotSink> _logger;

    public OfflineQueueScreenshotSink(IOfflineEventStore eventStore, ILogger<OfflineQueueScreenshotSink> logger)
    {
        _eventStore = eventStore;
        _logger = logger;
    }

    public void Submit(ScreenshotMetadata metadata)
    {
        var json = JsonSerializer.Serialize(metadata);
        _eventStore.Enqueue(OfflineEvent.Create(OfflineEventKind.Screenshot, json, metadata.CapturedAt));

        _logger.LogInformation(
            "Screenshot queued: monitor={Monitor} {Width}x{Height} {Size}B.",
            metadata.MonitorNumber, metadata.Width, metadata.Height, metadata.FileSize);
    }
}
