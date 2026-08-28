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

        Assert.Equal(28_800, overview.Activity.TrackedSeconds);
        Assert.Equal(75, overview.Activity.ActivePercent);
        Assert.Equal("organization", overview.Scope);
    }
}
