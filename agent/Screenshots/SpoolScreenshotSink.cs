using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Offline;
using Treck.Agent.Spooling;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Submits a completed capture to the interactive helper's event spool as a
/// <c>Screenshot</c> event (payload = <see cref="ScreenshotMetadata"/>, which
/// references the temp image path). The Session-0 service ingests it into the
/// offline queue, so the service remains the single writer of the SQLite database.
/// </summary>
public sealed class SpoolScreenshotSink : IScreenshotSink
{
    private readonly ILogger<SpoolScreenshotSink> _logger;
    private readonly IAgentEventSpool _spool;

    public SpoolScreenshotSink(ILogger<SpoolScreenshotSink> logger, IAgentEventSpool spool)
    {
        _logger = logger;
        _spool = spool;
    }

    public void Submit(ScreenshotMetadata metadata)
    {
        var json = JsonSerializer.Serialize(metadata);
        _spool.Submit(OfflineEvent.Create(OfflineEventKind.Screenshot, json, metadata.CapturedAt));

        _logger.LogInformation(
            "Screenshot spooled: monitor={Monitor} {Width}x{Height} {Size}B.",
            metadata.MonitorNumber, metadata.Width, metadata.Height, metadata.FileSize);
    }
}
