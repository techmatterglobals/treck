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
    private readonly AgentOptions _options;
    private readonly ILogger<DeviceRegistrationService> _logger;

    public DeviceRegistrationService(
        ITreckApiClient api,
        IDeviceIdStore deviceIdStore,
        ITokenStore tokenStore,
        IOptions<AgentOptions> options,
        ILogger<DeviceRegistrationService> logger)
    {
        _api = api;
        _deviceIdStore = deviceIdStore;
        _tokenStore = tokenStore;
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

    private async Task<string> RegisterAsync(CancellationToken cancellationToken)
    {
        // New (enrollment-based) installs have no provisioning key: they are
        // enrolled once via `TreckAgent.exe --enroll <CODE>`, which stores the
        // device token directly. Reaching here without a token AND without a key
        // means the device was never enrolled — fail with a clear, actionable
        // message instead of a doomed empty-key registration attempt. The legacy
        // provisioning-key flow (key present) is unchanged.
        if (string.IsNullOrWhiteSpace(_options.ProvisioningKey))
        {
            throw new InvalidOperationException(
                "This device is not enrolled and no provisioning key is configured. "
                + "Run 'TreckAgent.exe --enroll <CODE>' to enroll it, or set Agent:ProvisioningKey "
                + "(+ Agent:EmployeeCode) for the legacy registration flow.");
        }

        var deviceUuid = _deviceIdStore.GetOrCreate();

        var request = new RegisterDeviceRequest(
            ProvisioningKey: _options.ProvisioningKey,
            DeviceUuid: deviceUuid,
            EmployeeCode: _options.EmployeeCode,
            ComputerName: Environment.MachineName,
            Os: RuntimeInformation.OSDescription,
            AgentVersion: AgentVersion);

        // Requirement 14: structured log for every registration attempt
        // (never logs the provisioning key or the returned token).
        _logger.LogInformation(
            "Registering device {DeviceUuid} as employee {EmployeeCode} ({ComputerName})",
            deviceUuid, _options.EmployeeCode, request.ComputerName);

        try
        {
            var response = await _api.RegisterDeviceAsync(request, cancellationToken);

            _tokenStore.Save(response.Token);

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
