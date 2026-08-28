namespace Treck.Agent.Models;

/// <summary>
/// Body of POST /api/agent/register. Serialized with a snake_case naming policy
/// (provisioning_key, device_uuid, …) to match the Laravel API.
/// </summary>
public sealed record RegisterDeviceRequest(
    string ProvisioningKey,
    string DeviceUuid,
    string EmployeeCode,
    string ComputerName,
    string Os,
    string AgentVersion);
