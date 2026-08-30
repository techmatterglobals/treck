namespace Treck.Agent.Models;

public sealed record AgentHealthReportRequest(
    string AgentVersion,
    string ConfigRevision,
    int PendingEventCount,
    bool HelperRunning,
    int? HelperSessionId,
    DateTimeOffset? ServiceStartedAt,
    DateTimeOffset? LastCaptureAt,
    DateTimeOffset? LastSuccessfulSyncAt,
    string? LastErrorCategory,
    DateTimeOffset ReportTime);
