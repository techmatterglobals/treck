using Treck.Admin.Application.Services;
using Treck.Admin.Application.ViewModels;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class RoleAwareNavigationTests
{
    [Fact]
    public async Task Navigation_requires_both_feature_and_permission()
    {
        var api = new SessionServiceTests.StubDesktopApi();
        var auth = new SessionServiceTests.StubAuthenticationApi();
        var tokens = new SessionServiceTests.MemoryTokenStore();
        var organizations = new CurrentOrganizationService(new SessionServiceTests.MemoryOrganizationStore());
        await organizations.SelectAsync(SessionServiceTests.AdminOrganization());
        var session = new SessionService(auth, api, tokens, organizations);
        var polling = new PollingLoop();
        var shell = new ShellViewModel(api, session, organizations,
            new OverviewViewModel(api, polling, organizations),
            new PresenceViewModel(api, polling, organizations),
            new AgentHealthViewModel(api, polling, organizations),
            new EmployeeDetailViewModel(api));

        shell.Initialize(SessionServiceTests.Bootstrap(screenshots: false, reports: true));

        Assert.Contains(shell.Navigation, item => item.Key == "reports");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "screenshots");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "health");
    }

    [Fact]
    public async Task Agent_health_navigation_is_enabled_by_feature_flag()
    {
        var api = new SessionServiceTests.StubDesktopApi();
        var auth = new SessionServiceTests.StubAuthenticationApi();
        var tokens = new SessionServiceTests.MemoryTokenStore();
        var organizations = new CurrentOrganizationService(new SessionServiceTests.MemoryOrganizationStore());
        await organizations.SelectAsync(SessionServiceTests.AdminOrganization(health: true));
        var session = new SessionService(auth, api, tokens, organizations);
        var polling = new PollingLoop();
        var shell = new ShellViewModel(api, session, organizations,
            new OverviewViewModel(api, polling, organizations),
            new PresenceViewModel(api, polling, organizations),
            new AgentHealthViewModel(api, polling, organizations),
            new EmployeeDetailViewModel(api));

        shell.Initialize(SessionServiceTests.Bootstrap(health: true));

        Assert.Contains(shell.Navigation, item => item.Key == "health");
    }

    [Fact]
    public async Task Navigation_is_rebuilt_from_selected_organization_capabilities()
    {
        var api = new SessionServiceTests.StubDesktopApi();
        var organizations = new CurrentOrganizationService(new SessionServiceTests.MemoryOrganizationStore());
        await organizations.SelectAsync(SessionServiceTests.ManagerOrganization());
        var session = new SessionService(new SessionServiceTests.StubAuthenticationApi(), api,
            new SessionServiceTests.MemoryTokenStore(), organizations);
        var polling = new PollingLoop();
        var shell = new ShellViewModel(api, session, organizations,
            new OverviewViewModel(api, polling, organizations),
            new PresenceViewModel(api, polling, organizations),
            new AgentHealthViewModel(api, polling, organizations),
            new EmployeeDetailViewModel(api));

        shell.Initialize(SessionServiceTests.Bootstrap([SessionServiceTests.ManagerOrganization()]));

        Assert.Contains(shell.Navigation, item => item.Key == "presence");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "attendance");
        Assert.DoesNotContain(shell.Navigation, item => item.Key == "reports");
        Assert.Equal("manager", shell.UserRole);
    }
}
