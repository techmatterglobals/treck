namespace Treck.Agent.Models;

/// <summary>
/// Body of POST /api/agent/register. Serialized with a snake_case naming policy
/// (enrollment_secret, device_uuid, …) to match the Laravel API.
/// </summary>
public sealed record RegisterDeviceRequest(
    string EnrollmentSecret,
    string DeviceUuid,
    string EmployeeCode,
    string ComputerName,
    string Os,
    string AgentVersion);
