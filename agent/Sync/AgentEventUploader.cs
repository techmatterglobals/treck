using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Offline;
using Treck.Agent.Screenshots;
using Treck.Agent.Services;

namespace Treck.Agent.Sync;

/// <summary>
/// Uploads a queued event via the device-token-authenticated API. Obtains the
/// bearer token from the registration service and re-registers on a 401. This is
/// the only place the sync path touches the API (storage stays isolated).
///
/// Most events are JSON and go to <c>/api/agent/events</c>. Screenshots are
/// binary: their queue payload is the <see cref="ScreenshotMetadata"/> (with the
/// temp-file path), so they are delegated to the <see cref="IScreenshotSyncService"/>,
/// which reads the temp file, POSTs it multipart, and deletes it on success.
/// Either way the item rides the same offline queue, ordering and backoff.
/// </summary>
public sealed class AgentEventUploader : IEventUploader
{
    private readonly ITreckApiClient _api;
    private readonly IDeviceRegistrationService _registration;
    private readonly IScreenshotSyncService _screenshots;
    private readonly ILogger<AgentEventUploader> _logger;

    public AgentEventUploader(
        ITreckApiClient api,
        IDeviceRegistrationService registration,
        IScreenshotSyncService screenshots,
        ILogger<AgentEventUploader> logger)
    {
        _api = api;
        _registration = registration;
        _screenshots = screenshots;
        _logger = logger;
    }

    public async Task<bool> TryUploadAsync(OfflineEvent offlineEvent, CancellationToken cancellationToken)
    {
        var token = await _registration.EnsureRegisteredAsync(cancellationToken);

        try
        {
            if (offlineEvent.Kind == OfflineEventKind.Screenshot)
            {
                return await UploadScreenshotAsync(token, offlineEvent, cancellationToken);
            }

            var payload = new OfflineEventPayload(
                Kind: ToWireKind(offlineEvent.Kind),
                IdempotencyKey: offlineEvent.IdempotencyKey,
                CreatedAt: offlineEvent.CreatedAtUtc,
                Payload: offlineEvent.PayloadJson);

            return await _api.UploadEventAsync(token, payload, cancellationToken);
        }
        catch (UnauthorizedApiException)
        {
            _logger.LogWarning("Upload rejected (401); re-registering device.");
            await _registration.ReRegisterAsync(cancellationToken);
            return false; // retried next cycle with the fresh token
        }
    }

    private async Task<bool> UploadScreenshotAsync(string token, OfflineEvent offlineEvent, CancellationToken cancellationToken)
    {
        var metadata = JsonSerializer.Deserialize<ScreenshotMetadata>(offlineEvent.PayloadJson);

        if (metadata is null)
        {
            _logger.LogWarning("Screenshot event {Id} had an unreadable payload; dropping.", offlineEvent.Id);
            return true; // unrecoverable → drop so the queue does not wedge
        }

        return await _screenshots.UploadAsync(token, metadata, cancellationToken);
    }

    /// <summary>
    /// Map the offline kind to the server's wire vocabulary
    /// (<see cref="Treck.Agent.Offline.OfflineEventKind"/> → AgentEventKind).
    /// AppUsage needs an explicit mapping because its wire value is snake_case.
    /// </summary>
    private static string ToWireKind(OfflineEventKind kind) => kind switch
    {
        OfflineEventKind.Heartbeat => "heartbeat",
        OfflineEventKind.Session => "session",
        OfflineEventKind.AppUsage => "app_usage",
        _ => kind.ToString().ToLowerInvariant(),
    };
}
