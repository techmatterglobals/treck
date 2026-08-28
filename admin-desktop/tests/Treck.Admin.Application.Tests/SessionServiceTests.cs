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
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens);

        var session = await service.SignInAsync("admin@example.com", "secret");

        Assert.Equal("token-123", tokens.Token);
        Assert.Equal("Admin User", session.User.Name);
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
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens);

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
        var service = new SessionService(new StubAuthenticationApi(), desktop, tokens);

        await Assert.ThrowsAsync<TreckApiException>(() => service.RestoreAsync());

        Assert.Null(tokens.Token);
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
        var service = new SessionService(authentication, new StubDesktopApi(), tokens);

        await service.SignOutAsync();

        Assert.Null(tokens.Token);
        Assert.Equal(1, tokens.ClearCalls);
    }

    internal static DesktopBootstrap Bootstrap(bool screenshots = true, bool reports = true) => new(
        new DesktopUser(1, "Admin User", "admin@example.com"),
        ["admin"],
        ["view dashboard", "view attendance", "view reports"],
        new DesktopFeatures(true, true, reports, true, screenshots, true, false),
        new ServerInformation("1.0.0", "UTC", "Asia/Karachi", DateTimeOffset.UtcNow));

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

    internal sealed class StubAuthenticationApi : ITreckAuthenticationApi
    {
        public Exception? LogoutException { get; init; }
        public Task<LoginSession> LoginAsync(string email, string password, string deviceName,
            CancellationToken cancellationToken = default) => Task.FromResult(new LoginSession(
                "token-123", "Bearer", new DesktopUser(1, "Admin User", email), ["admin"], ["*"]));
        public Task LogoutAsync(CancellationToken cancellationToken = default) =>
            LogoutException is null ? Task.CompletedTask : Task.FromException(LogoutException);
    }

    internal sealed class StubDesktopApi : ITreckDesktopApi
    {
        public DesktopBootstrap? Bootstrap { get; init; }
        public Exception? Exception { get; init; }
        public int BootstrapCalls { get; private set; }
        public Task<DesktopBootstrap> GetBootstrapAsync(CancellationToken cancellationToken = default)
        {
            BootstrapCalls++;
            return Exception is null
                ? Task.FromResult(Bootstrap ?? SessionServiceTests.Bootstrap())
                : Task.FromException<DesktopBootstrap>(Exception);
        }
        public Task<DesktopOverview> GetOverviewAsync(CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }
}
