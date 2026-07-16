using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Moq;
using Treck.Agent.Activity;
using Treck.Agent.Configuration;
using Xunit;

namespace Treck.Agent.Tests;

public class HeartbeatCalculatorTests
{
    private static readonly DateTimeOffset Now = new(2026, 7, 15, 9, 0, 0, TimeSpan.Zero);

    [Fact]
    public void Active_interval_when_idle_below_threshold()
    {
        var hb = HeartbeatCalculator.Create(Now, TimeSpan.FromSeconds(60), TimeSpan.FromSeconds(10), TimeSpan.FromSeconds(300));

        Assert.False(hb.IsIdle);
        Assert.Equal(60, hb.ActiveSeconds);
        Assert.Equal(0, hb.IdleSeconds);
        Assert.Equal(10, hb.IdleTimeSeconds);
    }

    [Fact]
    public void Idle_interval_when_idle_at_or_above_threshold()
    {
        var hb = HeartbeatCalculator.Create(Now, TimeSpan.FromSeconds(60), TimeSpan.FromSeconds(400), TimeSpan.FromSeconds(300));

        Assert.True(hb.IsIdle);
        Assert.Equal(0, hb.ActiveSeconds);
        Assert.Equal(60, hb.IdleSeconds);
    }

    [Fact]
    public void Threshold_boundary_counts_as_idle()
    {
        var hb = HeartbeatCalculator.Create(Now, TimeSpan.FromSeconds(60), TimeSpan.FromSeconds(300), TimeSpan.FromSeconds(300));

        Assert.True(hb.IsIdle);
    }

    [Fact]
    public void Negative_elapsed_is_clamped_to_zero()
    {
        var hb = HeartbeatCalculator.Create(Now, TimeSpan.FromSeconds(-5), TimeSpan.Zero, TimeSpan.FromSeconds(300));

        Assert.Equal(0, hb.ElapsedSeconds);
        Assert.Equal(0, hb.ActiveSeconds);
    }
}

public class HeartbeatSchedulerTests
{
    private static HeartbeatScheduler Build(Mock<IIdleDetector> idle, MutableTimeProvider time, int idleThreshold = 300)
    {
        var options = Options.Create(new AgentOptions
        {
            BaseUrl = "https://treck.test",
            ProvisioningKey = "k",
            EmployeeCode = "EMP-1",
            HeartbeatIntervalSeconds = 60,
            IdleThresholdSeconds = idleThreshold,
        });

        return new HeartbeatScheduler(NullLogger<HeartbeatScheduler>.Instance, idle.Object, options, time);
    }

    [Fact]
    public void CaptureHeartbeat_raises_active_event_when_input_recent()
    {
        var idle = new Mock<IIdleDetector>();
        idle.Setup(d => d.GetIdleTime()).Returns(TimeSpan.FromSeconds(5));
        var scheduler = Build(idle, new MutableTimeProvider());

        HeartbeatEvent? captured = null;
        scheduler.HeartbeatProduced += (_, e) => captured = e;

        var returned = scheduler.CaptureHeartbeat();

        Assert.NotNull(captured);
        Assert.False(captured!.IsIdle);
        Assert.Equal(60, captured.ActiveSeconds); // first interval == configured interval
        Assert.Equal(0, captured.IdleSeconds);
        Assert.Equal(captured, returned);
    }

    [Fact]
    public void CaptureHeartbeat_raises_idle_event_when_idle_exceeds_threshold()
    {
        var idle = new Mock<IIdleDetector>();
        idle.Setup(d => d.GetIdleTime()).Returns(TimeSpan.FromSeconds(500));
        var scheduler = Build(idle, new MutableTimeProvider());

        var hb = scheduler.CaptureHeartbeat();

        Assert.True(hb.IsIdle);
        Assert.Equal(0, hb.ActiveSeconds);
        Assert.Equal(60, hb.IdleSeconds);
    }

    [Fact]
    public void Elapsed_is_measured_between_captures()
    {
        var idle = new Mock<IIdleDetector>();
        idle.Setup(d => d.GetIdleTime()).Returns(TimeSpan.FromSeconds(1));
        var time = new MutableTimeProvider();
        var scheduler = Build(idle, time);

        scheduler.CaptureHeartbeat();          // first: elapsed == interval (60)
        time.Advance(TimeSpan.FromSeconds(90));
        var second = scheduler.CaptureHeartbeat();

        Assert.Equal(90, second.ElapsedSeconds);
        Assert.Equal(90, second.ActiveSeconds);
    }
}
