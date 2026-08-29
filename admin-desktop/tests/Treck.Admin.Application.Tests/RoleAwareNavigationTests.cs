using Treck.Admin.Application.Services;
using Treck.Admin.Application.ViewModels;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class RoleAwareNavigationTests
{
    [Fact]
    public void Navigation_requires_both_feature_and_permission()
    {
        var api = new SessionServiceTests.StubDesktopApi();
        var auth = new SessionServiceTests.StubAuthenticationApi();
        var tokens = new SessionServiceTests.MemoryTokenStore();
        var session = new SessionService(auth, api, tokens);
        var polling = new PollingLoop();
        var shell = new ShellViewModel(api, session,
            new OverviewViewModel(api, polling),
            new PresenceViewModel(api, polling),
            new AgentHealthViewModel(api, polling),
            new EmployeeDetailViewModel(api));

        shell.Initialize(SessionServiceTests.Bootstrap(screenshots: false, reports: true));

        Assert.Contains(shell.Navigation, item => item.Key == "reports");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "screenshots");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "health");
    }

    [Fact]
    public void Agent_health_navigation_is_enabled_by_feature_flag()
    {
        var api = new SessionServiceTests.StubDesktopApi();
        var auth = new SessionServiceTests.StubAuthenticationApi();
        var tokens = new SessionServiceTests.MemoryTokenStore();
        var session = new SessionService(auth, api, tokens);
        var polling = new PollingLoop();
        var shell = new ShellViewModel(api, session,
            new OverviewViewModel(api, polling),
            new PresenceViewModel(api, polling),
            new AgentHealthViewModel(api, polling),
            new EmployeeDetailViewModel(api));

        shell.Initialize(SessionServiceTests.Bootstrap(health: true));

        Assert.Contains(shell.Navigation, item => item.Key == "health");
    }
}
