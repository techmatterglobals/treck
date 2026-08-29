using System.Reflection;
using System.Runtime.InteropServices;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Api;
using Treck.Agent.Configuration;
using Treck.Agent.Models;
using Treck.Agent.Storage;

namespace Treck.Agent.Services;

/// <summary>
/// Ties together the device id, API client, and encrypted token store.
/// Depends only on abstractions (SOLID / DIP), so each collaborator is
/// independently testable and swappable.
/// </summary>
public sealed class DeviceRegistrationService : IDeviceRegistrationService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly ITreckApiClient _api;
    private readonly IDeviceIdStore _deviceIdStore;
    private readonly ITokenStore _tokenStore;
    private readonly IEnrollmentSecretStore _enrollmentSecretStore;
    private readonly AgentOptions _options;
    private readonly ILogger<DeviceRegistrationService> _logger;
    private static readonly TimeSpan RegistrationLockTimeout = TimeSpan.FromSeconds(30);

    public DeviceRegistrationService(
        ITreckApiClient api,
        IDeviceIdStore deviceIdStore,
        ITokenStore tokenStore,
        IEnrollmentSecretStore enrollmentSecretStore,
        IOptions<AgentOptions> options,
        ILogger<DeviceRegistrationService> logger)
    {
        _api = api;
        _deviceIdStore = deviceIdStore;
        _tokenStore = tokenStore;
        _enrollmentSecretStore = enrollmentSecretStore;
        _options = options.Value;
        _logger = logger;
    }

    public bool IsRegistered => _tokenStore.HasToken;

    public async Task<string> EnsureRegisteredAsync(CancellationToken cancellationToken)
    {
        // Requirement 10: decrypt the existing token on startup, if present.
        var existing = _tokenStore.TryLoad();
        if (existing is not null)
        {
            _logger.LogInformation("Device already registered; using stored token.");
            return existing;
        }

        return await RegisterAsync(cancellationToken);
    }

    public async Task<string> ReRegisterAsync(CancellationToken cancellationToken)
    {
        _logger.LogWarning("Re-registering device (previous token invalid or cleared).");
        _tokenStore.Clear();

        return await RegisterAsync(cancellationToken);
    }

    private Task<string> RegisterAsync(CancellationToken cancellationToken) =>
        Task.Run(() =>
        {
            using var mutex = new Mutex(false, @"Global\TreckAgentRegistration");
            var lockAcquired = false;

            try
            {
                try
                {
                    lockAcquired = mutex.WaitOne(RegistrationLockTimeout);
                }
                catch (AbandonedMutexException ex)
                {
                    lockAcquired = true;
                    _logger.LogWarning(ex, "Device registration lock was abandoned; continuing with ownership.");
                }

                if (!lockAcquired)
                {
                    throw new TimeoutException("Timed out waiting for the device registration lock.");
                }

                var existing = _tokenStore.TryLoad();
                if (existing is not null)
                {
                    _logger.LogInformation("Device was registered by another worker while waiting for the lock.");
                    return existing;
                }

                return RegisterWithLockHeldAsync(cancellationToken).GetAwaiter().GetResult();
            }
            finally
            {
                if (lockAcquired)
                {
                    mutex.ReleaseMutex();
                }
            }
        }, cancellationToken);

    private async Task<string> RegisterWithLockHeldAsync(CancellationToken cancellationToken)
    {
        var deviceUuid = _deviceIdStore.GetOrCreate();
        var enrollmentSecret = _enrollmentSecretStore.TryLoad()
            ?? throw new InvalidOperationException(
                $"No enrollment secret was supplied. Set {EnrollmentSecretStore.EnvironmentVariable} or install {EnrollmentSecretStore.FileName} under %ProgramData%\\TreckAgent.");

        var request = new RegisterDeviceRequest(
            EnrollmentSecret: enrollmentSecret,
            DeviceUuid: deviceUuid,
            EmployeeCode: _options.EmployeeCode,
            ComputerName: Environment.MachineName,
            Os: RuntimeInformation.OSDescription,
            AgentVersion: AgentVersion);

        // Requirement 14: structured log for every registration attempt
        // (never logs the enrollment secret or the returned token).
        _logger.LogInformation(
            "Registering device {DeviceUuid} as employee {EmployeeCode} ({ComputerName})",
            deviceUuid, _options.EmployeeCode, request.ComputerName);

        try
        {
            var response = await _api.RegisterDeviceAsync(request, cancellationToken);

            _tokenStore.Save(response.Token);
            _enrollmentSecretStore.DeleteFileSecret();

            _logger.LogInformation(
                "Device registered: computerId={ComputerId} employeeId={EmployeeId}",
                response.ComputerId, response.EmployeeId);

            return response.Token;
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Device registration failed for {DeviceUuid}", deviceUuid);
            throw;
        }
    }
}
