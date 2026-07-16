using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Treck.Agent.Offline;
using Xunit;

namespace Treck.Agent.Tests;

public class OfflineStoreTests
{
    private static SqliteEventStore NewStore(TempPaths paths, OfflineStoreOptions? options = null)
    {
        var store = new SqliteEventStore(
            paths,
            Options.Create(options ?? new OfflineStoreOptions()),
            NullLogger<SqliteEventStore>.Instance,
            TimeProvider.System);
        store.Initialize();
        return store;
    }

    private static OfflineEvent Heartbeat(string payload)
        => OfflineEvent.Create(OfflineEventKind.Heartbeat, payload, DateTimeOffset.UnixEpoch);

    [Fact]
    public void Enqueue_saves_a_pending_event()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);

        store.Enqueue(Heartbeat("{\"a\":1}"));

        Assert.Equal(1, store.CountPending());
    }

    [Fact]
    public void GetPending_returns_events_in_insertion_order()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);

        store.Enqueue(Heartbeat("first"));
        store.Enqueue(Heartbeat("second"));
        store.Enqueue(Heartbeat("third"));

        var pending = store.GetPending(10);

        Assert.Equal(new[] { "first", "second", "third" }, pending.Select(e => e.PayloadJson));
        Assert.True(pending[0].Id < pending[1].Id && pending[1].Id < pending[2].Id);
    }

    [Fact]
    public void Duplicate_idempotency_key_is_ignored()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);

        var a = new OfflineEvent { IdempotencyKey = "dup", Kind = OfflineEventKind.Heartbeat, PayloadJson = "{}", CreatedAtUtc = DateTimeOffset.UnixEpoch };
        var b = new OfflineEvent { IdempotencyKey = "dup", Kind = OfflineEventKind.Heartbeat, PayloadJson = "{}", CreatedAtUtc = DateTimeOffset.UnixEpoch };

        store.Enqueue(a);
        store.Enqueue(b);

        Assert.Equal(1, store.CountPending());
    }

    [Fact]
    public void MarkSynced_removes_events_from_pending()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        store.Enqueue(Heartbeat("x"));
        var ids = store.GetPending(10).Select(e => e.Id).ToList();

        store.MarkSynced(ids);

        Assert.Equal(0, store.CountPending());
    }

    [Fact]
    public void RecordFailure_keeps_events_pending_and_counts_attempts()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        store.Enqueue(Heartbeat("x"));
        var id = store.GetPending(10)[0].Id;

        store.RecordFailure(new[] { id }, "boom");

        var pending = store.GetPending(10);
        Assert.Single(pending);
        Assert.Equal(1, pending[0].Attempts);
    }

    [Fact]
    public void Events_survive_a_restart()
    {
        using var paths = new TempPaths();

        using (var store1 = NewStore(paths))
        {
            store1.Enqueue(Heartbeat("persisted-1"));
            store1.Enqueue(Heartbeat("persisted-2"));
        }

        // New store instance over the same database file (simulates a restart).
        using var store2 = NewStore(paths);

        var pending = store2.GetPending(10);
        Assert.Equal(2, pending.Count);
        Assert.Equal("persisted-1", pending[0].PayloadJson);
    }
}
