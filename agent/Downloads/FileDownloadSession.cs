namespace Treck.Agent.Downloads;

/// <summary>
/// Tracks one in-progress download (Phase 12) while the monitor waits for its
/// size to stabilize. Browsers write to temp names (.crdownload/.part) and grow
/// the file over time; the monitor debounces on this session until the size has
/// stopped changing for the configured window, then reports it exactly once.
/// </summary>
public sealed class FileDownloadSession
{
    public FileDownloadSession(string path, long size, DateTimeOffset firstSeenUtc)
    {
        Path = path;
        LastSize = size;
        LastChangedUtc = firstSeenUtc;
    }

    public string Path { get; }

    public long LastSize { get; private set; }

    public DateTimeOffset LastChangedUtc { get; private set; }

    public bool Reported { get; private set; }

    /// <summary>Record the latest observed size, resetting the stability clock on change.</summary>
    public void Observe(long size, DateTimeOffset nowUtc)
    {
        if (size != LastSize)
        {
            LastSize = size;
            LastChangedUtc = nowUtc;
        }
    }

    /// <summary>Whether the file has been unchanged long enough to report.</summary>
    public bool IsStable(DateTimeOffset nowUtc, int stabilizationMs)
        => !Reported && (nowUtc - LastChangedUtc).TotalMilliseconds >= stabilizationMs;

    public void MarkReported() => Reported = true;
}
