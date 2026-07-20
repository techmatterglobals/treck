namespace Treck.Agent.Applications;

/// <summary>
/// Raised by an <see cref="IApplicationTracker"/> whenever the foreground
/// application or its window title changes. <see cref="Application"/> is null
/// when there is no eligible foreground window (e.g. the desktop is locked or
/// the shell has focus).
/// </summary>
public sealed class ApplicationChangedEventArgs : EventArgs
{
    public ApplicationChangedEventArgs(ApplicationInfo? application, DateTimeOffset timestampUtc)
    {
        Application = application;
        TimestampUtc = timestampUtc;
    }

    public ApplicationInfo? Application { get; }

    public DateTimeOffset TimestampUtc { get; }
}
