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
        new("ENROLL-SECRET", "uuid-1", "EMP-1", "PC-1", "Windows 11", "1.0.0");

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
        Assert.Contains("enrollment_secret", handler.LastBody);
        Assert.Contains("device_uuid", handler.LastBody);
        Assert.DoesNotContain("EnrollmentSecret", handler.LastBody); // proves snake_case serialization
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

    private static OfflineEventPayload SamplePayload() =>
        new(Kind: "heartbeat",
            IdempotencyKey: "idem-1",
            CreatedAt: DateTimeOffset.UnixEpoch,
            Payload: "{\"ElapsedSeconds\":60}");

    [Fact]
    public async Task UploadEventAsync_posts_snake_case_body_with_bearer_and_returns_true()
    {
        const string json = """{"message":"Event stored.","data":{"id":1,"duplicate":false}}""";
        var handler = new StubHandler(HttpStatusCode.Created, json);
        var client = ClientWith(handler);

        var ok = await client.UploadEventAsync("12|token", SamplePayload(), CancellationToken.None);

        Assert.True(ok);
        Assert.Equal("api/agent/events", handler.LastRequest!.RequestUri!.AbsolutePath.TrimStart('/'));
        Assert.Equal("Bearer", handler.LastRequest.Headers.Authorization!.Scheme);
        Assert.Equal("12|token", handler.LastRequest.Headers.Authorization.Parameter);
        Assert.Contains("idempotency_key", handler.LastBody);
        Assert.Contains("created_at", handler.LastBody);
        Assert.DoesNotContain("IdempotencyKey", handler.LastBody); // proves snake_case serialization
    }

    [Fact]
    public async Task UploadEventAsync_treats_200_as_acknowledged_duplicate()
    {
        const string json = """{"message":"Event already recorded.","data":{"id":1,"duplicate":true}}""";
        var handler = new StubHandler(HttpStatusCode.OK, json);
        var client = ClientWith(handler);

        // A 200 (idempotent re-submission) is still success → agent may drop it.
        Assert.True(await client.UploadEventAsync("t", SamplePayload(), CancellationToken.None));
    }

    [Fact]
    public async Task UploadEventAsync_throws_Unauthorized_on_401()
    {
        var handler = new StubHandler(HttpStatusCode.Unauthorized, "{}");
        var client = ClientWith(handler);

        await Assert.ThrowsAsync<UnauthorizedApiException>(
            () => client.UploadEventAsync("t", SamplePayload(), CancellationToken.None));
    }

    [Fact]
    public async Task UploadEventAsync_returns_false_on_server_error_so_event_stays_queued()
    {
        var handler = new StubHandler(HttpStatusCode.InternalServerError, "{}");
        var client = ClientWith(handler);

        Assert.False(await client.UploadEventAsync("t", SamplePayload(), CancellationToken.None));
    }
}
