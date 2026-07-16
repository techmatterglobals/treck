using Treck.Agent.Models;

namespace Treck.Agent.Api;

/// <summary>Typed client for the Treck HTTP API (Milestone 2: registration only).</summary>
public interface ITreckApiClient
{
    /// <summary>
    /// Registers this device and returns its credentials (device token, ids).
    /// Throws <see cref="ApiException"/> on a non-success response.
    /// </summary>
    Task<RegisterDeviceResponse> RegisterDeviceAsync(RegisterDeviceRequest request, CancellationToken cancellationToken);

    /// <summary>
    /// Uploads one queued event to <c>/api/agent/events</c> using the device
    /// bearer token. Returns true on any 2xx (stored or idempotent duplicate);
    /// throws <see cref="UnauthorizedApiException"/> on 401 so the caller can
    /// re-register. Non-success leaves the event queued for retry.
    /// </summary>
    Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken);
}
