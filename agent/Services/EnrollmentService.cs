using System.Reflection;
using System.Runtime.InteropServices;
using Microsoft.Extensions.Logging;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Storage;

namespace Treck.Agent.Services;

/// <summary>
/// One-shot enrollment (installer / `TreckAgent.exe --enroll &lt;CODE&gt;`). Reuses
/// the existing device-identity store (<see cref="IDeviceIdStore"/>), DPAPI token
/// store (<see cref="ITokenStore"/>) and API client (<see cref="ITreckApiClient"/>);
/// it introduces no new storage or security mechanism, and never starts the
/// monitoring loop.
///
/// SECURITY: the enrollment code and the returned token are never logged. On any
/// failure path the code is not echoed.
/// </summary>
public sealed class EnrollmentService : IEnrollmentService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ITreckApiClient _api;
    private readonly IDeviceIdStore _deviceIdStore;
    private readonly ITokenStore _tokenStore;
    private readonly ILogger<EnrollmentService> _logger;

    public EnrollmentService(
        ITreckApiClient api,
        IDeviceIdStore deviceIdStore,
        ITokenStore tokenStore,
        ILogger<EnrollmentService> logger)
    {
        _api = api;
        _deviceIdStore = deviceIdStore;
        _tokenStore = tokenStore;
        _logger = logger;
    }

    public async Task<int> RunAsync(string? code, bool force, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(code))
        {
            _logger.LogError("No enrollment code provided. Usage: TreckAgent.exe --enroll <CODE> [--base-url <URL>] [--force].");

            return EnrollmentExitCode.UsageError;
        }

        // Do not silently overwrite an existing device credential.
        if (_tokenStore.HasToken && !force)
        {
            _logger.LogWarning(
                "This computer is already enrolled. Re-run with --force to enroll again "
                + "(that replaces the stored device credential).");

            return EnrollmentExitCode.AlreadyEnrolled;
        }

        // Stable device identity: reuse the existing store, which only generates
        // an id on first run and returns the same value thereafter.
        var deviceUuid = _deviceIdStore.GetOrCreate();

        EnrollmentResponse response;
        try
        {
            response = await _api.EnrollAsync(
                new EnrollmentRequest(
                    Code: code,
                    DeviceUuid: deviceUuid,
                    ComputerName: Environment.MachineName,
                    Os: RuntimeInformation.OSDescription,
                    AgentVersion: AgentVersion),
                cancellationToken);
        }
        catch (EnrollmentRejectedException ex)
        {
            // Safe message (no code); server rejected the code.
            _logger.LogError("Enrollment failed: {Reason}", ex.Message);

            return EnrollmentExitCode.CodeRejected;
        }
        catch (ApiException ex)
        {
            _logger.LogError("Enrollment could not complete (server/network error, status {Status}).", ex.StatusCode);

            return EnrollmentExitCode.ServerUnavailable;
        }
        catch (Exception)
        {
            // Never include the code or any request detail in the message.
            _logger.LogError("Enrollment failed due to an unexpected error contacting the server.");

            return EnrollmentExitCode.ServerUnavailable;
        }

        if (response is null || string.IsNullOrWhiteSpace(response.Token))
        {
            _logger.LogError("Enrollment returned an invalid response (no device token).");

            return EnrollmentExitCode.InvalidResponse;
        }

        // Persist the credential with the EXISTING DPAPI token store (ciphertext
        // only). The token is never written to appsettings.json and never logged.
        _tokenStore.Save(response.Token);

        _logger.LogInformation(
            "Enrollment succeeded. ComputerId={ComputerId} DeviceId={DeviceId}. The Treck Agent service can now start.",
            response.ComputerId,
            deviceUuid);

        return EnrollmentExitCode.Success;
    }
}
