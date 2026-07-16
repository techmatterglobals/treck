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
    /// Uploads one queued event using the device bearer token. Returns true on a
    /// success response; throws <see cref="UnauthorizedApiException"/> on 401 so
    /// the caller can re-register. (Server endpoint: M6.)
    /// </summary>
    Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken);
}
