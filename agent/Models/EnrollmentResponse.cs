namespace Treck.Agent.Models;

/// <summary>The `data` payload returned by POST /api/agent/enroll.</summary>
public sealed record EnrollmentResponse(
    long ComputerId,
    string DeviceId,
    string Token,
    string? TokenType);
