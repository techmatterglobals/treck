using System.Net;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class SessionServiceTests
{
    [Fact]
    public async Task Sign_in_persists_token_only_after_login_and_validates_bootstrap()
    {
        var tokens = new MemoryTokenStore();
        var desktop = new StubDesktopApi { Bootstrap = Bootstrap() };
        var organizations = new CurrentOrganizationService(new MemoryOrganizationStore());
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens, organizations);

        var session = await service.SignInAsync("admin@example.com", "secret");

        Assert.Equal("token-123", tokens.Token);
        Assert.Equal("Admin User", session.User.Name);
        Assert.Equal(10, organizations.SelectedOrganizationId);
        Assert.Equal(1, desktop.BootstrapCalls);
    }

    [Fact]
    public async Task Forbidden_bootstrap_clears_new_login_token()
    {
        var tokens = new MemoryTokenStore();
        var desktop = new StubDesktopApi
        {
            Exception = new TreckApiException(HttpStatusCode.Forbidden, "Forbidden"),
        };
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens, new CurrentOrganizationService(new MemoryOrganizationStore()));

        await Assert.ThrowsAsync<TreckApiException>(() => service.SignInAsync("employee@example.com", "secret"));

        Assert.Null(tokens.Token);
        Assert.Equal(1, tokens.ClearCalls);
    }

    [Fact]
    public async Task Unauthorized_restored_session_is_removed()
    {
        var tokens = new MemoryTokenStore { Token = "expired" };
        var desktop = new StubDesktopApi
        {
            Exception = new TreckApiException(HttpStatusCode.Unauthorized, "Expired"),
        };
        var organizations = new CurrentOrganizationService(new MemoryOrganizationStore { OrganizationId = 10 });
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens, organizations);

        await Assert.ThrowsAsync<TreckApiException>(() => service.RestoreAsync());

        Assert.Null(tokens.Token);
        Assert.Null(organizations.SelectedOrganizationId);
        Assert.Equal(1, tokens.ClearCalls);
    }

    [Fact]
    public async Task Sign_out_clears_local_token_when_server_already_rejected_it()
    {
        var tokens = new MemoryTokenStore { Token = "revoked" };
        var authentication = new StubAuthenticationApi
        {
            LogoutException = new TreckApiException(HttpStatusCode.Unauthorized, "Expired"),
        };
        var organizations = new CurrentOrganizationService(new MemoryOrganizationStore { OrganizationId = 10 });
        await organizations.SelectAsync(AdminOrganization());
        var service = new SessionService(authentication, new StubDesktopApi(), tokens, organizations);

        await service.SignOutAsync();

        Assert.Null(tokens.Token);
        Assert.Null(organizations.SelectedOrganizationId);
        Assert.Equal(1, tokens.ClearCalls);
    }

    [Fact]
    public async Task Restore_revalidates_saved_organization_against_bootstrap()
    {
        var tokens = new MemoryTokenStore { Token = "restored" };
        var store = new MemoryOrganizationStore { OrganizationId = 99 };
        var organizations = new CurrentOrganizationService(store);
        var service = new SessionService(new StubAuthenticationApi(), new StubDesktopApi { Bootstrap = Bootstrap() }, tokens, organizations);

        await service.RestoreAsync();

        Assert.Equal(10, organizations.SelectedOrganizationId);
        Assert.Equal(10, store.OrganizationId);
    }

    [Fact]
    public async Task Invalid_saved_organization_is_cleared_when_no_single_recommendation_exists()
    {
        var tokens = new MemoryTokenStore { Token = "restored" };
        var store = new MemoryOrganizationStore { OrganizationId = 99 };
        var organizations = new CurrentOrganizationService(store);
        var service = new SessionService(new StubAuthenticationApi(), new StubDesktopApi
        {
            Bootstrap = Bootstrap([AdminOrganization(10, "First"), ManagerOrganization(20, "Second")]),
        }, tokens, organizations);

        await service.RestoreAsync();

        Assert.Null(organizations.SelectedOrganizationId);
        Assert.Null(store.OrganizationId);
    }

    internal static DesktopBootstrap Bootstrap(bool screenshots = true, bool reports = true, bool health = false) => new(
        "desktop-v2",
        new DesktopUser(1, "Admin User", "admin@example.com"),
        ["admin"],
        ["view dashboard", "view attendance", "view reports"],
        [AdminOrganization(screenshots: screenshots, reports: reports, health: health)],
        false,
        AdminOrganization(screenshots: screenshots, reports: reports, health: health),
        new DesktopFeatures(true, true, reports, true, screenshots, true, health),
        new ServerInformation("1.0.0", "UTC", "Asia/Karachi", DateTimeOffset.UtcNow));

    internal static DesktopBootstrap Bootstrap(IReadOnlyList<DesktopOrganization> organizations) => new(
        "desktop-v2",
        new DesktopUser(1, "Admin User", "admin@example.com"),
        organizations.Select(organization => organization.Role).Distinct().ToArray(),
        organizations.SelectMany(organization => organization.Permissions).Distinct().ToArray(),
        organizations,
        organizations.Count != 1,
        organizations.Count == 1 ? organizations[0] : null,
        new DesktopFeatures(true, true, true, true, true, true, true),
        new ServerInformation("1.0.0", "UTC", "Asia/Karachi", DateTimeOffset.UtcNow));

    internal static DesktopOrganization AdminOrganization(
        long id = 10,
        string name = "Acme",
        bool screenshots = true,
        bool reports = true,
        bool health = false) => new(
            id, name, "acme", "active", "admin",
            reports ? ["view dashboard", "view attendance", "view reports"] : ["view dashboard", "view attendance"],
            new DesktopFeatures(true, true, reports, true, screenshots, true, health, true, true, false));

    internal static DesktopOrganization ManagerOrganization(long id = 20, string name = "Branch") => new(
        id, name, "branch", "active", "manager", ["view dashboard"],
        new DesktopFeatures(true, false, false, true, true, true, true, true, false, true));

    internal sealed class MemoryTokenStore : IAccessTokenStore
    {
        public string? Token { get; set; }
        public int ClearCalls { get; private set; }
        public Task<string?> ReadAsync(CancellationToken cancellationToken = default) => Task.FromResult(Token);
        public Task WriteAsync(string token, CancellationToken cancellationToken = default)
        {
            Token = token;
            return Task.CompletedTask;
        }
        public Task ClearAsync(CancellationToken cancellationToken = default)
        {
            Token = null;
            ClearCalls++;
            return Task.CompletedTask;
        }
    }

    internal sealed class MemoryOrganizationStore : ISelectedOrganizationStore
    {
        public long? OrganizationId { get; set; }
        public int ClearCalls { get; private set; }
        public Task<long?> ReadAsync(CancellationToken cancellationToken = default) => Task.FromResult(OrganizationId);
        public Task WriteAsync(long organizationId, CancellationToken cancellationToken = default)
        {
            OrganizationId = organizationId;
            return Task.CompletedTask;
        }
        public Task ClearAsync(CancellationToken cancellationToken = default)
        {
            OrganizationId = null;
            ClearCalls++;
            return Task.CompletedTask;
        }
    }

    internal sealed class StubAuthenticationApi : ITreckAuthenticationApi
    {
        public Exception? LogoutException { get; init; }
        public Task<LoginSession> LoginAsync(string email, string password, string deviceName,
            CancellationToken cancellationToken = default) => Task.FromResult(new LoginSession(
                "token-123", "Bearer", new DesktopUser(1, "Admin User", email), ["admin"], ["*"]));
        public Task LogoutAsync(CancellationToken cancellationToken = default) =>
            LogoutException is null ? Task.CompletedTask : Task.FromException(LogoutException);
    }

    internal class StubDesktopApi : ITreckDesktopApi
    {
        public DesktopBootstrap? Bootstrap { get; init; }
        public DesktopOverview? Overview { get; init; }
        public DesktopPresence? Presence { get; init; }
        public DesktopAgentHealth? AgentHealth { get; init; }
        public EmployeeDetail? Employee { get; init; }
        public Exception? Exception { get; init; }
        public int BootstrapCalls { get; private set; }
        public TaskCompletionSource<bool> OverviewCalled { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public TaskCompletionSource<bool> AgentHealthCalled { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public Task<DesktopBootstrap> GetBootstrapAsync(CancellationToken cancellationToken = default)
        {
            BootstrapCalls++;
            return Exception is null
                ? Task.FromResult(Bootstrap ?? SessionServiceTests.Bootstrap())
                : Task.FromException<DesktopBootstrap>(Exception);
        }
        public virtual Task<DesktopOverview> GetOverviewAsync(CancellationToken cancellationToken = default)
        {
            OverviewCalled.TrySetResult(true);
            return Task.FromResult(Overview ?? new DesktopOverview(
                DateOnly.FromDateTime(DateTime.Today), new EmployeeOverview(0, 0, 0),
                new PresenceOverview(0, 0, 0, 0, 0, 0, 0), new ActivityOverview(0, 0, 0, 0),
                "organization", DateTimeOffset.UtcNow));
        }
        public Task<DesktopPresence> GetPresenceAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(Presence ?? new DesktopPresence([], new PresenceOverview(0, 0, 0, 0, 0, 0, 0), 30, DateTimeOffset.UtcNow));
        public Task<DesktopAgentHealth> GetAgentHealthAsync(CancellationToken cancellationToken = default)
        {
            AgentHealthCalled.TrySetResult(true);
            return Task.FromResult(AgentHealth ?? new DesktopAgentHealth([], new AgentHealthSummary(0, 0, 0, 0, 0, 0), 60, DateTimeOffset.UtcNow));
        }
        public Task<EmployeeDetail> GetEmployeeAsync(long employeeId, CancellationToken cancellationToken = default) =>
            Employee is null ? throw new NotSupportedException() : Task.FromResult(Employee);
    }
}
