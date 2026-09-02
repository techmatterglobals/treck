namespace Treck.Agent.Models;

/// <summary>
/// Body of POST /api/agent/enroll (installer flow). Serialized snake_case
/// (code, device_uuid, computer_name, …) to match the Laravel API. There is
/// deliberately NO employee_code and NO provisioning_key here — enrollment is
/// computer-scoped and gated only by the one-time enrollment code.
/// </summary>
public sealed record EnrollmentRequest(
    string Code,
    string DeviceUuid,
    string? ComputerName,
    string? Os,
    string? AgentVersion);
