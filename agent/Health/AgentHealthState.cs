namespace Treck.Agent.Health;

public sealed class AgentHealthState
{
    private readonly object _gate = new();

    public bool HelperRunning { get; private set; }
    public int? HelperSessionId { get; private set; }
    public DateTimeOffset ServiceStartedAt { get; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? LastCaptureAt { get; private set; }
    public DateTimeOffset? LastSuccessfulSyncAt { get; private set; }
    public string? LastErrorCategory { get; private set; }

    public void UpdateHelper(bool running, int? sessionId)
    {
        lock (_gate)
        {
            HelperRunning = running;
            HelperSessionId = sessionId;
        }
    }

    public void MarkCaptureSynced(DateTimeOffset capturedAt)
    {
        lock (_gate)
        {
            LastCaptureAt = capturedAt;
            LastSuccessfulSyncAt = DateTimeOffset.UtcNow;
            LastErrorCategory = null;
        }
    }

    public void MarkSyncSucceeded()
    {
        lock (_gate)
        {
            LastSuccessfulSyncAt = DateTimeOffset.UtcNow;
            LastErrorCategory = null;
        }
    }

    public void MarkError(string category)
    {
        lock (_gate)
        {
            LastErrorCategory = category.Length > 80 ? category[..80] : category;
        }
    }

    public Snapshot Current()
    {
        lock (_gate)
        {
            return new Snapshot(
                HelperRunning,
                HelperSessionId,
                ServiceStartedAt,
                LastCaptureAt,
                LastSuccessfulSyncAt,
                LastErrorCategory);
        }
    }

    public sealed record Snapshot(
        bool HelperRunning,
        int? HelperSessionId,
        DateTimeOffset ServiceStartedAt,
        DateTimeOffset? LastCaptureAt,
        DateTimeOffset? LastSuccessfulSyncAt,
        string? LastErrorCategory);
}
