using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Sessions;
using Xunit;

namespace Treck.Agent.Tests;

public class SessionMonitorTests
{
    /// <summary>A time source we can advance deterministically in tests.</summary>
    private sealed class MutableTimeProvider : TimeProvider
    {
        public DateTimeOffset UtcNow = DateTimeOffset.UnixEpoch;

        public override DateTimeOffset GetUtcNow() => UtcNow;

        public void Advance(TimeSpan by) => UtcNow = UtcNow.Add(by);
    }

    /// <summary>Test double exposing the protected Publish() for the base logic.</summary>
    private sealed class TestSessionMonitor : SessionMonitorBase
    {
        public TestSessionMonitor(SessionMonitorOptions options, TimeProvider time)
            : base(NullLogger<TestSessionMonitor>.Instance, options, time)
        {
        }

        protected override void OnStart()
        {
        }

        protected override void OnStop()
        {
        }

        public void Emit(SessionEventType type) => Publish(type);
    }

    private static (TestSessionMonitor monitor, List<SessionEvent> received, MutableTimeProvider time) Build(int suppressionMs)
    {
        var time = new MutableTimeProvider();
        var monitor = new TestSessionMonitor(new SessionMonitorOptions { DuplicateSuppressionMilliseconds = suppressionMs }, time);
        var received = new List<SessionEvent>();
        monitor.SessionChanged += (_, e) => received.Add(e);
        return (monitor, received, time);
    }

    [Fact]
    public void Publish_raises_event_with_type_and_timestamp()
    {
        var (monitor, received, time) = Build(suppressionMs: 1000);
        time.UtcNow = new DateTimeOffset(2026, 7, 15, 9, 0, 0, TimeSpan.Zero);

        monitor.Emit(SessionEventType.Logon);

        var evt = Assert.Single(received);
        Assert.Equal(SessionEventType.Logon, evt.Type);
        Assert.Equal(time.UtcNow, evt.TimestampUtc);
    }

    [Fact]
    public void Publish_suppresses_duplicate_same_type_within_window()
    {
        var (monitor, received, _) = Build(suppressionMs: 1000);

        monitor.Emit(SessionEventType.Lock);
        monitor.Emit(SessionEventType.Lock);

        Assert.Single(received);
    }

    [Fact]
    public void Publish_allows_different_types()
    {
        var (monitor, received, _) = Build(suppressionMs: 1000);

        monitor.Emit(SessionEventType.Lock);
        monitor.Emit(SessionEventType.Unlock);

        Assert.Equal(2, received.Count);
        Assert.Equal(SessionEventType.Lock, received[0].Type);
        Assert.Equal(SessionEventType.Unlock, received[1].Type);
    }

    [Fact]
    public void Publish_allows_same_type_after_window_elapses()
    {
        var (monitor, received, time) = Build(suppressionMs: 1000);

        monitor.Emit(SessionEventType.Lock);
        time.Advance(TimeSpan.FromMilliseconds(1500));
        monitor.Emit(SessionEventType.Lock);

        Assert.Equal(2, received.Count);
    }

    [Fact]
    public void Zero_window_never_suppresses()
    {
        var (monitor, received, _) = Build(suppressionMs: 0);

        monitor.Emit(SessionEventType.Lock);
        monitor.Emit(SessionEventType.Lock);

        Assert.Equal(2, received.Count);
    }
}
