using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Treck.Agent.Offline;
using Treck.Agent.Sync;
using Xunit;

namespace Treck.Agent.Tests;

public class SyncServiceTests
{
    private sealed class FakeUploader : IEventUploader
    {
        private readonly Func<OfflineEvent, bool> _decide;

        public int Calls { get; private set; }

        public FakeUploader(Func<OfflineEvent, bool> decide) => _decide = decide;

        public Task<bool> TryUploadAsync(OfflineEvent offlineEvent, CancellationToken cancellationToken)
        {
            Calls++;
            return Task.FromResult(_decide(offlineEvent));
        }
    }

    private static SqliteEventStore NewStore(TempPaths paths)
    {
        var store = new SqliteEventStore(
            paths,
            Options.Create(new OfflineStoreOptions()),
            NullLogger<SqliteEventStore>.Instance,
            TimeProvider.System);
        store.Initialize();
        return store;
    }

    private static SyncService NewSync(IOfflineEventStore store, IEventUploader uploader)
        => new(store, uploader, Options.Create(new OfflineStoreOptions()), NullLogger<SyncService>.Instance);

    private static void Seed(IOfflineEventStore store, int count)
    {
        for (var i = 0; i < count; i++)
        {
            store.Enqueue(OfflineEvent.Create(OfflineEventKind.Heartbeat, $"e{i}", DateTimeOffset.UnixEpoch));
        }
    }

    [Fact]
    public async Task Successful_sync_marks_all_events_synced()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        Seed(store, 2);
        var sync = NewSync(store, new FakeUploader(_ => true));

        var result = await sync.SyncPendingAsync(CancellationToken.None);

        Assert.Equal(2, result.Uploaded);
        Assert.Equal(0, result.RemainingPending);
        Assert.Equal(0, store.CountPending());
    }

    [Fact]
    public async Task Failed_sync_keeps_events()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        Seed(store, 2);
        var sync = NewSync(store, new FakeUploader(_ => false));

        var result = await sync.SyncPendingAsync(CancellationToken.None);

        Assert.Equal(0, result.Uploaded);
        Assert.Equal(2, store.CountPending()); // nothing lost
    }

    [Fact]
    public async Task Sync_stops_at_first_failure_preserving_order()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        Seed(store, 3);
        var firstId = store.GetPending(10)[0].Id;

        // Ack only the first event; the second fails → stop.
        var sync = NewSync(store, new FakeUploader(e => e.Id == firstId));

        var result = await sync.SyncPendingAsync(CancellationToken.None);

        Assert.Equal(1, result.Uploaded);
        Assert.Equal(2, store.CountPending());
    }

    [Fact]
    public async Task Empty_queue_is_a_noop()
    {
        using var paths = new TempPaths();
        using var store = NewStore(paths);
        var uploader = new FakeUploader(_ => true);
        var sync = NewSync(store, uploader);

        var result = await sync.SyncPendingAsync(CancellationToken.None);

        Assert.Equal(0, result.Uploaded);
        Assert.Equal(0, uploader.Calls);
    }
}
