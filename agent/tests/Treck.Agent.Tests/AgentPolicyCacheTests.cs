using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Configuration;
using Treck.Agent.Models;
using Xunit;

namespace Treck.Agent.Tests;

public sealed class AgentPolicyCacheTests
{
    [Fact]
    public void Save_replaces_the_last_known_good_policy_atomically()
    {
        using var paths = new TempPaths();
        var cache = new AgentPolicyCache(paths, NullLogger<AgentPolicyCache>.Instance);

        cache.Save(Config("rev-1"));
        cache.Save(Config("rev-2"));

        var loaded = cache.TryLoad();

        Assert.NotNull(loaded);
        Assert.Equal("rev-2", loaded.Revision);
        Assert.Empty(Directory.GetFiles(paths.BaseDirectory, "*.tmp"));
    }

    private static AgentConfigResponse Config(string revision) => new(
        ComputerId: 1,
        Revision: revision,
        ServerTime: DateTimeOffset.UtcNow,
        Policy: new AgentPolicy(
            OrganizationId: "org",
            MinimumAgentVersion: "1.0.0",
            HealthReportIntervalSeconds: 60,
            PresenceOfflineTimeoutSeconds: 180,
            Activity: new ActivityPolicy(60, 300),
            Screenshots: new ScreenshotPolicy(true, 600, true, 8192),
            Downloads: new DownloadPolicy(104857600, ["exe"], ["zip"])));
}
