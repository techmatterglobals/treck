using System.Net;
using System.Text;
using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Xunit;

namespace Treck.Agent.Tests;

public class TreckApiClientTests
{
    private sealed class StubHandler : HttpMessageHandler
    {
        private readonly HttpStatusCode _statusCode;
        private readonly string _json;

        public HttpRequestMessage? LastRequest { get; private set; }
        public string LastBody { get; private set; } = string.Empty;

        public StubHandler(HttpStatusCode statusCode, string json)
        {
            _statusCode = statusCode;
            _json = json;
        }

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            LastRequest = request;
            if (request.Content is not null)
            {
                LastBody = await request.Content.ReadAsStringAsync(cancellationToken);
            }

            return new HttpResponseMessage(_statusCode)
            {
                Content = new StringContent(_json, Encoding.UTF8, "application/json"),
            };
        }
    }

    private static TreckApiClient ClientWith(StubHandler handler) =>
        new(new HttpClient(handler) { BaseAddress = new Uri("https://treck.test/") },
            NullLogger<TreckApiClient>.Instance);

    private static RegisterDeviceRequest SampleRequest() =>
        new("PROV-KEY", "uuid-1", "EMP-1", "PC-1", "Windows 11", "1.0.0");

    [Fact]
    public async Task RegisterDeviceAsync_posts_snake_case_body_and_maps_the_response()
    {
        const string json = """
        {"message":"Device registered.","data":{"computer_id":12,"employee_id":42,"token":"12|abc","token_type":"Bearer"}}
        """;
        var handler = new StubHandler(HttpStatusCode.Created, json);
        var client = ClientWith(handler);

        var response = await client.RegisterDeviceAsync(SampleRequest(), CancellationToken.None);

        Assert.Equal(12, response.ComputerId);
        Assert.Equal(42, response.EmployeeId);
        Assert.Equal("12|abc", response.Token);
        Assert.Equal("Bearer", response.TokenType);

        Assert.Equal("api/agent/register", handler.LastRequest!.RequestUri!.AbsolutePath.TrimStart('/'));
        Assert.Contains("provisioning_key", handler.LastBody);
        Assert.Contains("device_uuid", handler.LastBody);
        Assert.DoesNotContain("ProvisioningKey", handler.LastBody); // proves snake_case serialization
    }

    [Fact]
    public async Task RegisterDeviceAsync_throws_Unauthorized_on_401()
    {
        var handler = new StubHandler(HttpStatusCode.Unauthorized, "{}");
        var client = ClientWith(handler);

        await Assert.ThrowsAsync<UnauthorizedApiException>(
            () => client.RegisterDeviceAsync(SampleRequest(), CancellationToken.None));
    }

    [Fact]
    public async Task RegisterDeviceAsync_throws_ApiException_on_server_error()
    {
        var handler = new StubHandler(HttpStatusCode.InternalServerError, "{}");
        var client = ClientWith(handler);

        var ex = await Assert.ThrowsAsync<ApiException>(
            () => client.RegisterDeviceAsync(SampleRequest(), CancellationToken.None));
        Assert.Equal(500, ex.StatusCode);
    }
}
