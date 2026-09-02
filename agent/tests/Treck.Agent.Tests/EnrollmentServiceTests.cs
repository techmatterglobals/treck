using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Screenshots;
using Treck.Agent.Services;
using Treck.Agent.Storage;
using Xunit;

namespace Treck.Agent.Tests;

public class EnrollmentServiceTests
{
    private const string DeviceUuid = "11111111-1111-4111-8111-111111111111";

    // --- Fakes (mirror the SyncServiceTests hand-written-fake style) ----------

    private sealed class FakeApi : ITreckApiClient
    {
        public Func<EnrollmentRequest, EnrollmentResponse>? OnEnroll { get; set; }
        public Func<EnrollmentRequest, Exception>? ThrowOnEnroll { get; set; }
        public EnrollmentRequest? LastRequest { get; private set; }
        public int EnrollCalls { get; private set; }

        public Task<EnrollmentResponse> EnrollAsync(EnrollmentRequest request, CancellationToken cancellationToken)
        {
            EnrollCalls++;
            LastRequest = request;
            if (ThrowOnEnroll is not null)
            {
                throw ThrowOnEnroll(request);
            }

            return Task.FromResult(OnEnroll!(request));
        }

        public Task<RegisterDeviceResponse> RegisterDeviceAsync(RegisterDeviceRequest request, CancellationToken cancellationToken)
            => throw new NotSupportedException();

        public Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken)
            => throw new NotSupportedException();

        public Task<bool> UploadScreenshotAsync(string bearerToken, ScreenshotMetadata metadata, byte[] imageBytes, CancellationToken cancellationToken)
            => throw new NotSupportedException();
    }

    private sealed class FakeTokenStore : ITokenStore
    {
        private string? _token;

        public FakeTokenStore(string? initial = null) => _token = initial;

        public bool HasToken => _token is not null;

        public string? TryLoad() => _token;

        public void Save(string token) => _token = token;

        public void Clear() => _token = null;
    }

    private sealed class FakeDeviceIdStore : IDeviceIdStore
    {
        public int Calls { get; private set; }

        public string GetOrCreate()
        {
            Calls++;

            return DeviceUuid;
        }
    }

    private static EnrollmentService NewService(FakeApi api, ITokenStore tokens, IDeviceIdStore? ids = null)
        => new(api, ids ?? new FakeDeviceIdStore(), tokens, NullLogger<EnrollmentService>.Instance);

    private static EnrollmentResponse Ok(string token = "tok-123")
        => new(ComputerId: 42, DeviceId: DeviceUuid, Token: token, TokenType: "Bearer");

    // --- Tests ----------------------------------------------------------------

    [Fact] // (1) request is correctly constructed
    public async Task Builds_request_from_device_identity_and_code()
    {
        var api = new FakeApi { OnEnroll = _ => Ok() };
        var svc = NewService(api, new FakeTokenStore());

        var exit = await svc.RunAsync("TRK-AAAA-BBBB-CCCC", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.Success, exit);
        Assert.Equal("TRK-AAAA-BBBB-CCCC", api.LastRequest!.Code);
        Assert.Equal(DeviceUuid, api.LastRequest!.DeviceUuid);
        Assert.False(string.IsNullOrWhiteSpace(api.LastRequest!.ComputerName));
    }

    [Fact] // (2,3) success stores identity + token via the existing stores
    public async Task Successful_enrollment_stores_token_and_uses_device_identity()
    {
        var api = new FakeApi { OnEnroll = _ => Ok("secret-token") };
        var tokens = new FakeTokenStore();
        var ids = new FakeDeviceIdStore();
        var svc = NewService(api, tokens, ids);

        var exit = await svc.RunAsync("TRK-CODE", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.Success, exit);
        Assert.Equal("secret-token", tokens.TryLoad()); // stored via ITokenStore (DPAPI in prod)
        Assert.True(ids.Calls >= 1);                     // device identity used
    }

    [Fact] // (4,5) enrollment carries no EmployeeCode and no ProvisioningKey
    public void Enrollment_request_model_has_no_employee_code_or_provisioning_key()
    {
        var props = typeof(EnrollmentRequest).GetProperties().Select(p => p.Name).ToArray();

        Assert.DoesNotContain("EmployeeCode", props);
        Assert.DoesNotContain("ProvisioningKey", props);
    }

    [Fact] // (6) invalid response is handled, nothing stored
    public async Task Invalid_response_without_token_returns_nonzero_and_stores_nothing()
    {
        var api = new FakeApi { OnEnroll = _ => new EnrollmentResponse(1, DeviceUuid, string.Empty, "Bearer") };
        var tokens = new FakeTokenStore();
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("TRK-CODE", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.InvalidResponse, exit);
        Assert.False(tokens.HasToken);
    }

    [Fact] // (6) rejected code (422)
    public async Task Rejected_code_returns_code_rejected()
    {
        var api = new FakeApi { ThrowOnEnroll = _ => new EnrollmentRejectedException("rejected") };
        var tokens = new FakeTokenStore();
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("TRK-BAD", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.CodeRejected, exit);
        Assert.False(tokens.HasToken);
    }

    [Fact] // (7) HTTP/server failure is handled
    public async Task Http_failure_returns_server_unavailable()
    {
        var api = new FakeApi { ThrowOnEnroll = _ => new ApiException("boom", 500) };
        var tokens = new FakeTokenStore();
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("TRK-CODE", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.ServerUnavailable, exit);
        Assert.False(tokens.HasToken);
    }

    [Fact] // (8) existing valid token is not silently overwritten
    public async Task Existing_token_is_not_overwritten_without_force()
    {
        var api = new FakeApi { OnEnroll = _ => Ok("new-token") };
        var tokens = new FakeTokenStore("existing-token");
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("TRK-CODE", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.AlreadyEnrolled, exit);
        Assert.Equal(0, api.EnrollCalls);                 // server never contacted
        Assert.Equal("existing-token", tokens.TryLoad()); // unchanged
    }

    [Fact] // (8) --force allows deliberate re-enrollment
    public async Task Force_allows_reenrollment_and_replaces_token()
    {
        var api = new FakeApi { OnEnroll = _ => Ok("new-token") };
        var tokens = new FakeTokenStore("existing-token");
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("TRK-CODE", force: true, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.Success, exit);
        Assert.Equal(1, api.EnrollCalls);
        Assert.Equal("new-token", tokens.TryLoad());
    }

    [Fact] // missing code → usage error, server not contacted
    public async Task Missing_code_returns_usage_error_without_contacting_server()
    {
        var api = new FakeApi { OnEnroll = _ => Ok() };
        var tokens = new FakeTokenStore();
        var svc = NewService(api, tokens);

        var exit = await svc.RunAsync("   ", force: false, CancellationToken.None);

        Assert.Equal(EnrollmentExitCode.UsageError, exit);
        Assert.Equal(0, api.EnrollCalls);
    }

    [Fact] // (11) device identity is stable across enrollments
    public async Task Device_identity_is_stable_across_enrollments()
    {
        var api = new FakeApi { OnEnroll = _ => Ok() };
        var svc = NewService(api, new FakeTokenStore(), new FakeDeviceIdStore());

        await svc.RunAsync("TRK-1", force: true, CancellationToken.None);
        var first = api.LastRequest!.DeviceUuid;
        await svc.RunAsync("TRK-2", force: true, CancellationToken.None);
        var second = api.LastRequest!.DeviceUuid;

        Assert.Equal(first, second);
        Assert.Equal(DeviceUuid, second);
    }
}
