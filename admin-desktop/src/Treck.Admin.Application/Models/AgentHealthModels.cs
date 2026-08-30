namespace Treck.Admin.Application.Models;

public sealed record DesktopAgentHealth(
    IReadOnlyList<AgentHealthRow> Items,
    AgentHealthSummary Summary,
    int RefreshAfterSeconds,
    DateTimeOffset GeneratedAt);

public sealed record AgentHealthSummary(
    int Total,
    int Healthy,
    int Stale,
    int NeverReported,
    int Outdated,
    int PendingEvents);

public sealed record AgentHealthRow(
    long ComputerId,
    long? EmployeeId,
    string ComputerName,
    string? Employee,
    string? Department,
    string Status,
    string? AgentVersion,
    string ExpectedVersion,
    string VersionCompliance,
    string? ConfigRevision,
    int? PendingEventCount,
    bool? HelperRunning,
    int? HelperSessionId,
    DateTimeOffset? ServiceStartedAt,
    DateTimeOffset? LastCaptureAt,
    DateTimeOffset? LastSuccessfulSyncAt,
    string? LastErrorCategory,
    DateTimeOffset? ReportedAt,
    DateTimeOffset? ReceivedAt);
