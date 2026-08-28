namespace Treck.Agent.Services;

/// <summary>
/// Orchestrates device identity + registration + token storage.
/// </summary>
public interface IDeviceRegistrationService
{
    /// <summary>True if a device token is currently stored.</summary>
    bool IsRegistered { get; }

    /// <summary>
    /// Ensures the device is registered and returns its bearer token. If a token
    /// is already stored it is decrypted and returned without calling the API.
    /// </summary>
    Task<string> EnsureRegisteredAsync(CancellationToken cancellationToken);

    /// <summary>
    /// Discards the stored token and registers again. Called when the current
    /// token is rejected (401) by an authenticated request in later milestones.
    /// </summary>
    Task<string> ReRegisterAsync(CancellationToken cancellationToken);
}
