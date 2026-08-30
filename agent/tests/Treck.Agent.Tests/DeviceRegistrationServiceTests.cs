using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Moq;
using Treck.Agent.Api;
using Treck.Agent.Configuration;
using Treck.Agent.Models;
using Treck.Agent.Services;
using Treck.Agent.Storage;
using Xunit;

namespace Treck.Agent.Tests;

public class DeviceRegistrationServiceTests
{
    private sealed class StubDeviceIdStore : IDeviceIdStore
    {
        public string GetOrCreate() => "uuid-1";
    }

    private sealed class StubEnrollmentSecretStore : IEnrollmentSecretStore
    {
        public int DeleteCalls { get; private set; }
        public string? TryLoad() => "enroll-once";
        public void DeleteFileSecret() => DeleteCalls++;
    }

    private sealed class CoordinatedTokenStore : ITokenStore
    {
        private readonly object _gate = new();
        private readonly CountdownEvent _outerLoads = new(2);
        private string? _token;
        private int _loadCalls;

        public bool HasToken
        {
            get
            {
                lock (_gate) return _token is not null;
            }
        }

        public string? TryLoad()
        {
            var call = Interlocked.Increment(ref _loadCalls);
            if (call <= 2)
            {
                _outerLoads.Signal();
                _outerLoads.Wait(TimeSpan.FromSeconds(2));
            }

            lock (_gate) return _token;
        }

        public void Save(string token)
        {
            lock (_gate) _token = token;
        }

        public void Clear()
        {
            lock (_gate) _token = null;
        }
    }

    private sealed class CountingApiClient : ITreckApiClient
    {
        private int _registrationCalls;
        public int RegistrationCalls => _registrationCalls;

        public async Task<RegisterDeviceResponse> RegisterDeviceAsync(
            RegisterDeviceRequest request,
            CancellationToken cancellationToken)
        {
            Interlocked.Increment(ref _registrationCalls);
            await Task.Delay(50, cancellationToken);
            return new RegisterDeviceResponse(1, 2, "shared-token", "Bearer");
        }

        public Task<AgentConfigResponse> GetAgentConfigAsync(string bearerToken, CancellationToken cancellationToken) =>
            throw new NotSupportedException();

        public Task<bool> ReportHealthAsync(string bearerToken, AgentHealthReportRequest request, CancellationToken cancellationToken) =>
            throw new NotSupportedException();

        public Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken) =>
            throw new NotSupportedException();

        public Task<bool> UploadScreenshotAsync(
            string bearerToken,
            Treck.Agent.Screenshots.ScreenshotMetadata metadata,
            byte[] imageBytes,
            CancellationToken cancellationToken) =>
            throw new NotSupportedException();
    }

    private static IOptions<AgentOptions> Options() => Microsoft.Extensions.Options.Options.Create(new AgentOptions
    {
        BaseUrl = "https://treck.test",
        EmployeeCode = "EMP-1",
    });

    private static DeviceRegistrationService Build(
        Mock<ITreckApiClient> api,
        Mock<IDeviceIdStore> ids,
        Mock<ITokenStore> tokens,
        Mock<IEnrollmentSecretStore>? enrollment = null) =>
        new(
            api.Object,
            ids.Object,
            tokens.Object,
            (enrollment ?? Enrollment("enroll-once")).Object,
            Options(),
            NullLogger<DeviceRegistrationService>.Instance);

    private static Mock<IEnrollmentSecretStore> Enrollment(string? secret)
    {
        var enrollment = new Mock<IEnrollmentSecretStore>();
        enrollment.Setup(e => e.TryLoad()).Returns(secret);
        return enrollment;
    }

    [Fact]
    public async Task EnsureRegistered_returns_stored_token_without_calling_the_api()
    {
        var api = new Mock<ITreckApiClient>();
        var ids = new Mock<IDeviceIdStore>();
        var tokens = new Mock<ITokenStore>();
        tokens.Setup(t => t.TryLoad()).Returns("stored-token");

        var token = await Build(api, ids, tokens).EnsureRegisteredAsync(CancellationToken.None);

        Assert.Equal("stored-token", token);
        api.Verify(a => a.RegisterDeviceAsync(It.IsAny<RegisterDeviceRequest>(), It.IsAny<CancellationToken>()), Times.Never);
    }

    [Fact]
    public async Task EnsureRegistered_registers_and_saves_encrypted_token_when_absent()
    {
        var api = new Mock<ITreckApiClient>();
        api.Setup(a => a.RegisterDeviceAsync(It.IsAny<RegisterDeviceRequest>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync(new RegisterDeviceResponse(1, 2, "new-token", "Bearer"));

        var ids = new Mock<IDeviceIdStore>();
        ids.Setup(i => i.GetOrCreate()).Returns("uuid-1");

        var tokens = new Mock<ITokenStore>();
        tokens.Setup(t => t.TryLoad()).Returns((string?)null);
        var enrollment = Enrollment("enroll-once");

        var token = await Build(api, ids, tokens, enrollment).EnsureRegisteredAsync(CancellationToken.None);

        Assert.Equal("new-token", token);
        tokens.Verify(t => t.Save("new-token"), Times.Once);
        enrollment.Verify(e => e.DeleteFileSecret(), Times.Once);
        api.Verify(a => a.RegisterDeviceAsync(
            It.Is<RegisterDeviceRequest>(request => request.EnrollmentSecret == "enroll-once"),
            It.IsAny<CancellationToken>()), Times.Once);
    }

    [Fact]
    public async Task ReRegister_clears_the_old_token_then_registers()
    {
        var api = new Mock<ITreckApiClient>();
        api.Setup(a => a.RegisterDeviceAsync(It.IsAny<RegisterDeviceRequest>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync(new RegisterDeviceResponse(1, 2, "fresh-token", "Bearer"));

        var ids = new Mock<IDeviceIdStore>();
        ids.Setup(i => i.GetOrCreate()).Returns("uuid-1");

        var tokens = new Mock<ITokenStore>();
        var enrollment = Enrollment("enroll-again");

        var token = await Build(api, ids, tokens, enrollment).ReRegisterAsync(CancellationToken.None);

        Assert.Equal("fresh-token", token);
        tokens.Verify(t => t.Clear(), Times.Once);
        tokens.Verify(t => t.Save("fresh-token"), Times.Once);
    }

    [Fact]
    public async Task EnsureRegistered_fails_without_an_enrollment_secret()
    {
        var api = new Mock<ITreckApiClient>();
        var ids = new Mock<IDeviceIdStore>();
        ids.Setup(i => i.GetOrCreate()).Returns("uuid-1");
        var tokens = new Mock<ITokenStore>();
        tokens.Setup(t => t.TryLoad()).Returns((string?)null);

        await Assert.ThrowsAsync<InvalidOperationException>(
            () => Build(api, ids, tokens, Enrollment(null)).EnsureRegisteredAsync(CancellationToken.None));
    }

    [Fact]
    public async Task Concurrent_EnsureRegistered_calls_share_one_registration_result()
    {
        var api = new CountingApiClient();
        var tokens = new CoordinatedTokenStore();
        var enrollment = new StubEnrollmentSecretStore();
        var service = new DeviceRegistrationService(
            api,
            new StubDeviceIdStore(),
            tokens,
            enrollment,
            Options(),
            NullLogger<DeviceRegistrationService>.Instance);

        var first = Task.Run(() => service.EnsureRegisteredAsync(CancellationToken.None));
        var second = Task.Run(() => service.EnsureRegisteredAsync(CancellationToken.None));
        var results = await Task.WhenAll(first, second);

        Assert.Equal(["shared-token", "shared-token"], results);
        Assert.Equal(1, api.RegistrationCalls);
        Assert.Equal(1, enrollment.DeleteCalls);
    }
}
