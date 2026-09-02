using System.Net;
using Treck.Admin.Api;
using Treck.Admin.Application.Services;
using Xunit;

namespace Treck.Admin.Application.Tests;

public sealed class OrganizationContextHandlerTests
{
    [Fact]
    public async Task Protected_desktop_requests_include_selected_organization_header()
    {
        var organizations = new CurrentOrganizationService(new SessionServiceTests.MemoryOrganizationStore());
        await organizations.SelectAsync(SessionServiceTests.AdminOrganization(id: 42));
        var terminal = new CaptureHandler();
        var handler = new OrganizationContextHandler(organizations) { InnerHandler = terminal };
        var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test/") };

        await client.GetAsync("api/v1/desktop/overview");

        Assert.Equal("42", terminal.Request!.Headers.GetValues(OrganizationContextHandler.HeaderName).Single());
    }

    [Fact]
    public async Task Bootstrap_requests_do_not_include_organization_header()
    {
        var organizations = new CurrentOrganizationService(new SessionServiceTests.MemoryOrganizationStore());
        await organizations.SelectAsync(SessionServiceTests.AdminOrganization(id: 42));
        var terminal = new CaptureHandler();
        var handler = new OrganizationContextHandler(organizations) { InnerHandler = terminal };
        var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test/") };

        await client.GetAsync("api/v1/desktop/bootstrap");

        Assert.False(terminal.Request!.Headers.Contains(OrganizationContextHandler.HeaderName));
    }

    private sealed class CaptureHandler : HttpMessageHandler
    {
        public HttpRequestMessage? Request { get; private set; }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Request = request;
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK));
        }
    }
}
