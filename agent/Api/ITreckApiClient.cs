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
}
