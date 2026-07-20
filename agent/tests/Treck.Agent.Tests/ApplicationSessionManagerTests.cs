using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Treck.Agent.Applications;
using Xunit;

namespace Treck.Agent.Tests;

/// <summary>
/// Phase 7 — application-usage session state machine. Verifies session
/// creation, application-switch and window-title-change boundaries, session
/// completion/flush, ignore rules, the minimum-duration filter and title
/// truncation — all without a real desktop.
/// </summary>
public class ApplicationSessionManagerTests
{
    private static (ApplicationSessionManager manager, List<ApplicationUsageEvent> completed, MutableTimeProvider time)
        Build(ApplicationTrackingOptions? options = null)
    {
        var time = new MutableTimeProvider { UtcNow = new DateTimeOffset(2026, 7, 20, 9, 0, 0, TimeSpan.Zero) };
        var manager = new ApplicationSessionManager(
            NullLogger<ApplicationSessionManager>.Instance,
            Options.Create(options ?? new ApplicationTrackingOptions { MinimumSessionSeconds = 0 }),
            time);

        var completed = new List<ApplicationUsageEvent>();
        manager.SessionCompleted += (_, e) => completed.Add(e);

        return (manager, completed, time);
    }

    private static ApplicationInfo App(string process, string exe, string title, int pid = 1000)
        => new(process, exe, title, pid, IsSystemProcess: false);

    [Fact]
    public void First_application_opens_a_session_but_emits_nothing_yet()
    {
        var (manager, completed, _) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), StartAt());

        Assert.Empty(completed); // Only COMPLETED sessions are emitted.
    }

    [Fact]
    public void Switching_application_completes_the_previous_session()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(120));
        manager.Track(App("Chrome", "chrome.exe", "Inbox"), time.UtcNow);

        var session = Assert.Single(completed);
        Assert.Equal("Code", session.ProcessName);
        Assert.Equal("Code.exe", session.ExecutableName);
        Assert.Equal("a.cs", session.WindowTitle);
        Assert.Equal(120, session.DurationSeconds);
    }

    [Fact]
    public void Window_title_change_within_same_process_starts_a_new_session()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Chrome", "chrome.exe", "Inbox"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(30));
        manager.Track(App("Chrome", "chrome.exe", "Docs"), time.UtcNow);

        var first = Assert.Single(completed);
        Assert.Equal("Inbox", first.WindowTitle);
        Assert.Equal(30, first.DurationSeconds);
    }

    [Fact]
    public void Same_application_and_title_is_a_no_op()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(10));
        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);

        Assert.Empty(completed); // Session is still open, unchanged.
    }

    [Fact]
    public void Each_completed_session_has_a_unique_id()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(5));
        manager.Track(App("Code", "Code.exe", "b.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(5));
        manager.Track(App("Slack", "slack.exe", "general"), time.UtcNow);

        Assert.Equal(2, completed.Count);
        Assert.NotEqual(completed[0].SessionId, completed[1].SessionId);
        Assert.All(completed, s => Assert.False(string.IsNullOrWhiteSpace(s.SessionId)));
    }

    [Fact]
    public void Flush_completes_the_open_session()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(45));
        manager.Flush(time.UtcNow);

        var session = Assert.Single(completed);
        Assert.Equal(45, session.DurationSeconds);
    }

    [Fact]
    public void Flush_with_no_open_session_emits_nothing()
    {
        var (manager, completed, time) = Build();

        manager.Flush(time.UtcNow);

        Assert.Empty(completed);
    }

    [Fact]
    public void Null_application_completes_the_open_session_and_opens_nothing()
    {
        var (manager, completed, time) = Build();

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(20));
        manager.Track(null, time.UtcNow); // e.g. desktop locked
        time.Advance(TimeSpan.FromSeconds(20));
        manager.Flush(time.UtcNow);

        var session = Assert.Single(completed); // second Flush has nothing open
        Assert.Equal(20, session.DurationSeconds);
    }

    [Fact]
    public void Ignored_executable_is_treated_as_no_application()
    {
        var options = new ApplicationTrackingOptions
        {
            MinimumSessionSeconds = 0,
            IgnoredExecutables = ["explorer.exe"],
        };
        var (manager, completed, time) = Build(options);

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(15));
        // Focus moves to Explorer (ignored): closes the Code session, opens none.
        manager.Track(App("explorer", "explorer.exe", "File Explorer"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(60));
        manager.Flush(time.UtcNow);

        var session = Assert.Single(completed);
        Assert.Equal("Code", session.ProcessName);
        Assert.Equal(15, session.DurationSeconds);
    }

    [Fact]
    public void Ignored_process_name_is_treated_as_no_application()
    {
        var options = new ApplicationTrackingOptions
        {
            MinimumSessionSeconds = 0,
            IgnoredProcessNames = ["Idle"],
        };
        var (manager, completed, time) = Build(options);

        manager.Track(new ApplicationInfo("Idle", null, "", 0, IsSystemProcess: true), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(30));
        manager.Flush(time.UtcNow);

        Assert.Empty(completed);
    }

    [Fact]
    public void Sub_minimum_sessions_are_dropped()
    {
        var options = new ApplicationTrackingOptions { MinimumSessionSeconds = 5 };
        var (manager, completed, time) = Build(options);

        manager.Track(App("Code", "Code.exe", "a.cs"), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(2)); // below the 5s minimum
        manager.Track(App("Chrome", "chrome.exe", "Inbox"), time.UtcNow);

        Assert.Empty(completed);
    }

    [Fact]
    public void Window_title_is_truncated_to_the_configured_maximum()
    {
        var options = new ApplicationTrackingOptions { MinimumSessionSeconds = 0, MaxWindowTitleLength = 10 };
        var (manager, completed, time) = Build(options);

        manager.Track(App("Code", "Code.exe", new string('x', 50)), time.UtcNow);
        time.Advance(TimeSpan.FromSeconds(5));
        manager.Flush(time.UtcNow);

        var session = Assert.Single(completed);
        Assert.Equal(10, session.WindowTitle.Length);
    }

    [Fact]
    public void Session_carries_start_and_end_timestamps()
    {
        var (manager, completed, time) = Build();
        var start = time.UtcNow;

        manager.Track(App("Code", "Code.exe", "a.cs"), start);
        time.Advance(TimeSpan.FromSeconds(90));
        manager.Flush(time.UtcNow);

        var session = Assert.Single(completed);
        Assert.Equal(start, session.StartedAt);
        Assert.Equal(start.AddSeconds(90), session.EndedAt);
    }

    private static DateTimeOffset StartAt() => new(2026, 7, 20, 9, 0, 0, TimeSpan.Zero);
}
