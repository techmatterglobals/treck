namespace Treck.Agent.Configuration;

/// <summary>
/// Strongly-typed agent configuration, bound from the "Agent" section of
/// appsettings.json (overridable by environment / MDM-deployed config).
/// </summary>
public sealed class AgentOptions
{
    public const string SectionName = "Agent";

    /// <summary>Base URL of the Laravel API, e.g. https://treck.example.com</summary>
    public string BaseUrl { get; set; } = "https://treck.example.com";

    /// <summary>Shared provisioning key used once for device registration.</summary>
    public string ProvisioningKey { get; set; } = string.Empty;

    /// <summary>Employee code this workstation is assigned to.</summary>
    public string EmployeeCode { get; set; } = string.Empty;

    /// <summary>How often to report activity.</summary>
    public int HeartbeatIntervalSeconds { get; set; } = 60;

    /// <summary>Idle-time threshold (seconds) above which time counts as idle.</summary>
    public int IdleThresholdSeconds { get; set; } = 300;

    /// <summary>Max retry attempts per API call (exponential backoff).</summary>
    public int MaxRetries { get; set; } = 4;
}
