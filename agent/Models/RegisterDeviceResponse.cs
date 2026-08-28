namespace Treck.Agent.Models;

/// <summary>The `data` payload returned by POST /api/agent/register.</summary>
public sealed record RegisterDeviceResponse(
    long ComputerId,
    long EmployeeId,
    string Token,
    string TokenType);
