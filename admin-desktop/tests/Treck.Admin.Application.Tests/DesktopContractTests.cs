using Treck.Admin.Application.Models;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class DesktopContractTests
{
    [Fact]
    public void Overview_contract_preserves_server_totals()
    {
        var overview = new DesktopOverview(
            new DateOnly(2026, 8, 28),
            new EmployeeOverview(10, 8, 80),
            new PresenceOverview(10, 7, 3, 5, 1, 1, 0),
            new ActivityOverview(21_600, 7_200, 28_800, 75),
            "organization",
            DateTimeOffset.UtcNow);

        Assert.Equal(28_800L, overview.Activity.TrackedSeconds);
        Assert.Equal(75d, overview.Activity.ActivePercent);
        Assert.Equal("organization", overview.Scope);
    }

    [Fact]
    public void Agent_health_contract_preserves_queue_and_status_fields()
    {
        var health = new DesktopAgentHealth(
            [new AgentHealthRow(1, 2, "PC-1", "Employee", "Ops", "stale", "0.9.0", "1.0.0",
                "outdated", "rev-a", 12, false, null, null, null, null, "sync_failed",
                DateTimeOffset.UtcNow.AddHours(-5), DateTimeOffset.UtcNow)],
            new AgentHealthSummary(1, 0, 1, 0, 1, 12),
            60,
            DateTimeOffset.UtcNow);

        Assert.Equal("stale", health.Items[0].Status);
        Assert.Equal(12, health.Items[0].PendingEventCount);
        Assert.Equal("sync_failed", health.Items[0].LastErrorCategory);
    }
}
