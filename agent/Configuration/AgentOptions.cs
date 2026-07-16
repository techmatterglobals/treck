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

    /// <summary>Shared provisioning key used once to register the device (M2).</summary>
    [Required]
    public string ProvisioningKey { get; set; } = string.Empty;

    /// <summary>Employee code this workstation is assigned to.</summary>
    [Required]
    public string EmployeeCode { get; set; } = string.Empty;

    /// <summary>How often to report activity, in seconds.</summary>
    [Range(10, 3600)]
    public int HeartbeatIntervalSeconds { get; set; } = 60;

    /// <summary>Idle-time threshold (seconds) above which time counts as idle.</summary>
    [Range(30, 86400)]
    public int IdleThresholdSeconds { get; set; } = 300;

    /// <summary>Max retry attempts per API call (used from M2 onward).</summary>
    [Range(0, 10)]
    public int MaxRetries { get; set; } = 4;
}
