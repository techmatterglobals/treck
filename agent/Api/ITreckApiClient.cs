using Treck.Agent.Models;
using Treck.Agent.Screenshots;

namespace Treck.Agent.Api;

/// <summary>Typed client for the Treck HTTP API.</summary>
public interface ITreckApiClient
{
    /// <summary>
    /// Registers this device and returns its credentials (device token, ids).
    /// Throws <see cref="ApiException"/> on a non-success response.
    /// </summary>
    Task<RegisterDeviceResponse> RegisterDeviceAsync(RegisterDeviceRequest request, CancellationToken cancellationToken);

    /// <summary>
    /// Enrolls this computer with a one-time enrollment code (installer flow) and
    /// returns its credentials (device token, ids). Never logs the code. Throws
    /// <see cref="EnrollmentRejectedException"/> when the code is rejected (422)
    /// and <see cref="ApiException"/> on other non-success responses.
    /// </summary>
    Task<EnrollmentResponse> EnrollAsync(EnrollmentRequest request, CancellationToken cancellationToken);

    /// <summary>
    /// Uploads one queued event to <c>/api/agent/events</c> using the device
    /// bearer token. Returns true on any 2xx (stored or idempotent duplicate);
    /// throws <see cref="UnauthorizedApiException"/> on 401 so the caller can
    /// re-register. Non-success leaves the event queued for retry.
    /// </summary>
    Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken);

    /// <summary>
    /// Uploads one screenshot to <c>/api/agent/screenshots</c> as multipart
    /// (image bytes + metadata fields) using the device bearer token. Returns
    /// true on any 2xx (stored or idempotent duplicate); throws
    /// <see cref="UnauthorizedApiException"/> on 401. Non-success leaves the
    /// screenshot queued for retry (order preserved).
    /// </summary>
    Task<bool> UploadScreenshotAsync(string bearerToken, ScreenshotMetadata metadata, byte[] imageBytes, CancellationToken cancellationToken);
}
