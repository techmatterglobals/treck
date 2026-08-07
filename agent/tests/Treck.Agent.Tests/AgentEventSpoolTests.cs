using System.Text.Json;
using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Offline;
using Treck.Agent.Spooling;
using Xunit;

namespace Treck.Agent.Tests;

/// <summary>
/// Phase 8 — the helper→service spool bridge. Verifies a submitted event is
/// written as a sidecar that faithfully round-trips back into an OfflineEvent
/// (idempotency key preserved, so the queue's dedup survives the handoff).
/// OS-agnostic: plain file I/O, no Windows APIs.
/// </summary>
public class AgentEventSpoolTests
{
    [Fact]
    public void Submit_writes_a_sidecar_that_round_trips_to_the_same_event()
    {
        using var paths = new TempPaths();
        var spool = new FileAgentEventSpool(NullLogger<FileAgentEventSpool>.Instance, paths);

        var original = OfflineEvent.Create(OfflineEventKind.Screenshot, "{\"ImageHash\":\"abc\"}", DateTimeOffset.UnixEpoch);
        spool.Submit(original);

        var sidecar = Directory.EnumerateFiles(HelperPaths.Spool(paths), "*.json").Single();
        var spooled = JsonSerializer.Deserialize<SpooledEvent>(File.ReadAllText(sidecar));
        var restored = spooled!.ToOfflineEvent();

        Assert.NotNull(restored);
        Assert.Equal(OfflineEventKind.Screenshot, restored!.Kind);
        Assert.Equal(original.IdempotencyKey, restored.IdempotencyKey);
        Assert.Equal(original.PayloadJson, restored.PayloadJson);
        Assert.Equal(original.CreatedAtUtc, restored.CreatedAtUtc);
    }

    [Fact]
    public void Different_kinds_round_trip()
    {
        using var paths = new TempPaths();
        var spool = new FileAgentEventSpool(NullLogger<FileAgentEventSpool>.Instance, paths);

        spool.Submit(OfflineEvent.Create(OfflineEventKind.Heartbeat, "{}", DateTimeOffset.UnixEpoch));
        spool.Submit(OfflineEvent.Create(OfflineEventKind.AppUsage, "{}", DateTimeOffset.UnixEpoch));

        var kinds = Directory.EnumerateFiles(HelperPaths.Spool(paths), "*.json")
            .Select(f => JsonSerializer.Deserialize<SpooledEvent>(File.ReadAllText(f))!.ToOfflineEvent()!.Kind)
            .ToList();

        Assert.Contains(OfflineEventKind.Heartbeat, kinds);
        Assert.Contains(OfflineEventKind.AppUsage, kinds);
    }

    [Fact]
    public void Unknown_kind_maps_to_null()
    {
        var spooled = new SpooledEvent("not_a_kind", "key", "{}", DateTimeOffset.UnixEpoch);
        Assert.Null(spooled.ToOfflineEvent());
    }
}
