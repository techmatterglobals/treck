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
}
