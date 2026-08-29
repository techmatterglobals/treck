using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;
using Treck.Admin.Application.ViewModels;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class LiveMonitoringViewModelTests
{
    [Fact]
    public async Task Overview_activation_refreshes_immediately_and_can_be_cancelled()
    {
        var expected = new DesktopOverview(
            new DateOnly(2026, 8, 28), new EmployeeOverview(12, 10, 83.3),
            new PresenceOverview(12, 9, 3, 7, 1, 1, 0),
            new ActivityOverview(21_600, 7_200, 28_800, 75), "team", DateTimeOffset.UtcNow);
        var api = new SessionServiceTests.StubDesktopApi { Overview = expected };
        var viewModel = new OverviewViewModel(api, new PollingLoop());

        viewModel.Activate();
        await api.OverviewCalled.Task.WaitAsync(TimeSpan.FromSeconds(1));
        for (var attempt = 0; attempt < 20 && viewModel.Overview is null; attempt++)
            await Task.Delay(5);
        viewModel.Deactivate();

        Assert.Same(expected, viewModel.Overview);
        Assert.Equal("Live", viewModel.ConnectionStatus);
        Assert.Equal("6h 00m", viewModel.ActiveTime);
    }

    [Fact]
    public void Presence_selection_requests_employee_detail()
    {
        var viewModel = new PresenceViewModel(new SessionServiceTests.StubDesktopApi(), new PollingLoop());
        long? requested = null;
        viewModel.EmployeeRequested += employeeId => requested = employeeId;
        viewModel.SelectedRow = new PresenceRow(5, 42, "PC-42", "Employee", "Operations", "active",
            DateTimeOffset.UtcNow, DateTimeOffset.UtcNow, 3600, 300);

        viewModel.OpenEmployeeCommand.Execute(null);

        Assert.Equal(42L, requested);
    }

    [Fact]
    public async Task Polling_loop_honors_cancellation_after_current_refresh()
    {
        using var cancellation = new CancellationTokenSource();
        var calls = 0;
        var loop = new PollingLoop();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => loop.RunAsync(
            TimeSpan.FromHours(1),
            _ =>
            {
                calls++;
                cancellation.Cancel();
                return Task.CompletedTask;
            },
            cancellation.Token));

        Assert.Equal(1, calls);
    }

    [Fact]
    public async Task Agent_health_activation_refreshes_immediately_and_can_be_cancelled()
    {
        var expected = new DesktopAgentHealth(
            [new AgentHealthRow(7, 9, "OPS-PC", "Employee", "Operations", "healthy", "1.0.0", "1.0.0",
                "compliant", "rev-1", 2, true, 3, DateTimeOffset.UtcNow.AddHours(-1),
                DateTimeOffset.UtcNow.AddMinutes(-2), DateTimeOffset.UtcNow.AddMinutes(-1), null,
                DateTimeOffset.UtcNow, DateTimeOffset.UtcNow)],
            new AgentHealthSummary(1, 1, 0, 0, 0, 2),
            60,
            DateTimeOffset.UtcNow);
        var api = new SessionServiceTests.StubDesktopApi { AgentHealth = expected };
        var viewModel = new AgentHealthViewModel(api, new PollingLoop());

        viewModel.Activate();
        await api.AgentHealthCalled.Task.WaitAsync(TimeSpan.FromSeconds(1));
        for (var attempt = 0; attempt < 20 && viewModel.Summary is null; attempt++)
            await Task.Delay(5);
        viewModel.Deactivate();

        Assert.Same(expected.Summary, viewModel.Summary);
        Assert.Equal("Live", viewModel.ConnectionStatus);
        Assert.Single(viewModel.Rows);
    }
}
