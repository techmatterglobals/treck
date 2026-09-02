using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Configuration;

/// <summary>
/// Strongly-typed agent configuration, bound from the "Agent" section of
/// appsettings.json and validated at startup (fail-fast via ValidateOnStart).
/// </summary>
public sealed class AgentOptions
{
    public const string SectionName = "Agent";

    /// <summary>Base URL of the Laravel API, e.g. https://treck.example.com</summary>
    [Required]
    [Url]
    public string BaseUrl { get; set; } = string.Empty;

    /// <summary>
    /// Shared provisioning key for the LEGACY registration flow (M2). Optional:
    /// new installs enroll with a one-time code (TreckAgent.exe --enroll) and
    /// store a device token instead, so this is absent from their config.
    /// </summary>
    public string ProvisioningKey { get; set; } = string.Empty;

    /// <summary>
    /// Employee code for the LEGACY registration flow. Optional: enrollment is
    /// computer-scoped (no employee code); the employee is resolved at runtime
    /// from the Windows username via computer_users.
    /// </summary>
    public string EmployeeCode { get; set; } = string.Empty;

    /// <summary>How often to report activity, in seconds.</summary>
    [Range(10, 3600)]
    public int HeartbeatIntervalSeconds { get; set; } = 60;

    /// <summary>Idle-time threshold (seconds) above which time counts as idle.</summary>
    [Range(30, 86400)]
    public int IdleThresholdSeconds { get; set; } = 300;

    /// <summary>Max retry attempts per API call (Polly exponential backoff).</summary>
    [Range(0, 10)]
    public int MaxRetries { get; set; } = 4;

    /// <summary>
    /// Local directory for persisted state (device id + encrypted token).
    /// Null → %ProgramData%\TreckAgent. Overridable for tests / portable installs.
    /// </summary>
    public string? StoragePath { get; set; }
}
