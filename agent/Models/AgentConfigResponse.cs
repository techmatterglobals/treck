namespace Treck.Agent.Models;

public sealed record AgentConfigResponse(
    long ComputerId,
    string Revision,
    DateTimeOffset ServerTime,
    AgentPolicy Policy);

public sealed record AgentPolicy(
    string OrganizationId,
    string MinimumAgentVersion,
    int HealthReportIntervalSeconds,
    int PresenceOfflineTimeoutSeconds,
    ActivityPolicy Activity,
    ScreenshotPolicy Screenshots,
    DownloadPolicy Downloads);

public sealed record ActivityPolicy(int HeartbeatIntervalSeconds, int IdleThresholdSeconds);

public sealed record ScreenshotPolicy(bool Enabled, int IntervalSeconds, bool Blur, int MaxUploadKb);

public sealed record DownloadPolicy(
    long LargeFileBytes,
    IReadOnlyList<string> ExecutableExtensions,
    IReadOnlyList<string> ArchiveExtensions);
