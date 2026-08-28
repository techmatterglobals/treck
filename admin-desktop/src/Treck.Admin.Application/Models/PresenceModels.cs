namespace Treck.Admin.Application.Models;

public sealed record DesktopPresence(
    IReadOnlyList<PresenceRow> Items,
    PresenceOverview Summary,
    int RefreshAfterSeconds,
    DateTimeOffset GeneratedAt);

public sealed record PresenceRow(
    long ComputerId,
    long? EmployeeId,
    string ComputerName,
    string? Employee,
    string? Department,
    string Status,
    DateTimeOffset? LastHeartbeatAt,
    DateTimeOffset? LastActivityAt,
    long ActiveSeconds,
    long IdleSeconds);

public sealed record EmployeeDetail(
    EmployeeIdentity Employee,
    EmployeeToday Today,
    IReadOnlyList<EmployeeComputer> Computers,
    DateTimeOffset GeneratedAt);

public sealed record EmployeeIdentity(
    long Id,
    string? Name,
    string? Email,
    string EmployeeCode,
    string? Designation,
    string? Department,
    string? Manager);

public sealed record EmployeeToday(
    long EmployeeId,
    DateOnly Date,
    long ActiveSeconds,
    long IdleSeconds,
    double ActiveHours,
    double IdleHours,
    double ActiveRatio,
    string Status,
    bool IsOnline,
    DateTimeOffset? LastActivityAt);

public sealed record EmployeeComputer(
    long ComputerId,
    string ComputerName,
    string Status,
    DateTimeOffset? LastHeartbeatAt,
    DateTimeOffset? LastActivityAt,
    long ActiveSeconds,
    long IdleSeconds);
