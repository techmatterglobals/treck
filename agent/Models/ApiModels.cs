namespace Treck.Agent.Models;

/// <summary>Live workstation status reported to the API.</summary>
public enum AgentStatus
{
    Online,
    Idle,
    Locked,
    Offline,
}

/// <summary>Every API response is wrapped as { "data": { ... } }.</summary>
public sealed record ApiEnvelope<T>(T Data);

// --- Responses (snake_case JSON mapped via SnakeCaseLower naming policy) ---

public sealed record RegisterData(long ComputerId, long EmployeeId, string Token);

public sealed record LoginData(long SessionId);

// --- Requests ---

public sealed record RegisterRequest(
    string ProvisioningKey,
    string DeviceUuid,
    string EmployeeCode,
    string ComputerName,
    string Os,
    string AgentVersion);

public sealed record LoginRequest(long EmployeeId, string ComputerName);

public sealed record ActivityRequest(long SessionId, int ActiveSeconds, int IdleSeconds, string Status);

public sealed record LogoutRequest(long SessionId, int ActiveSeconds, int IdleSeconds);

/// <summary>Result of classifying one tick of activity.</summary>
public readonly record struct ActivitySample(int ActiveSeconds, int IdleSeconds, AgentStatus Status);
