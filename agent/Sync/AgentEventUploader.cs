using Microsoft.Extensions.Logging;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Offline;
using Treck.Agent.Services;

namespace Treck.Agent.Sync;

/// <summary>
/// Uploads a queued event via the device-token-authenticated API. Obtains the
/// bearer token from the registration service and re-registers on a 401. This is
/// the only place the sync path touches the API (storage stays isolated).
/// </summary>
public sealed class AgentEventUploader : IEventUploader
{
    private readonly ITreckApiClient _api;
    private readonly IDeviceRegistrationService _registration;
    private readonly ILogger<AgentEventUploader> _logger;

    public AgentEventUploader(
        ITreckApiClient api,
        IDeviceRegistrationService registration,
        ILogger<AgentEventUploader> logger)
    {
        _api = api;
        _registration = registration;
        _logger = logger;
    }

    public async Task<bool> TryUploadAsync(OfflineEvent offlineEvent, CancellationToken cancellationToken)
    {
        var token = await _registration.EnsureRegisteredAsync(cancellationToken);

        var payload = new OfflineEventPayload(
            Kind: offlineEvent.Kind.ToString().ToLowerInvariant(),
            IdempotencyKey: offlineEvent.IdempotencyKey,
            CreatedAt: offlineEvent.CreatedAtUtc,
            Payload: offlineEvent.PayloadJson);

        try
        {
            return await _api.UploadEventAsync(token, payload, cancellationToken);
        }
        catch (UnauthorizedApiException)
        {
            _logger.LogWarning("Upload rejected (401); re-registering device.");
            await _registration.ReRegisterAsync(cancellationToken);
            return false; // retried next cycle with the fresh token
        }
    }
}
