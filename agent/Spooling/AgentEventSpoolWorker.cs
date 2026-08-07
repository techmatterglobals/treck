using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Treck.Agent.Offline;
using Treck.Agent.Storage;

namespace Treck.Agent.Spooling;

/// <summary>
/// Service-side bridge (Session 0): ingests spool sidecars written by the
/// interactive helper into the offline SQLite queue. Being the ONLY writer of the
/// queue avoids cross-process database contention; the existing SyncWorker then
/// uploads. Handles every helper-produced kind (screenshot, app_usage, heartbeat).
///
/// Polls oldest-first so upload order matches capture order. Corrupt sidecars are
/// discarded so a single bad file can never wedge the queue.
/// </summary>
public sealed class AgentEventSpoolWorker : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(5);

    private readonly ILogger<AgentEventSpoolWorker> _logger;
    private readonly IOfflineEventStore _eventStore;
    private readonly string _spoolDirectory;

    public AgentEventSpoolWorker(
        ILogger<AgentEventSpoolWorker> logger,
        IOfflineEventStore eventStore,
        IStoragePathProvider paths)
    {
        _logger = logger;
        _eventStore = eventStore;
        _spoolDirectory = HelperPaths.Spool(paths);
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        Directory.CreateDirectory(_spoolDirectory);
        _eventStore.Initialize();
        _logger.LogInformation("Agent event spool worker started (dir={Dir}).", _spoolDirectory);

        try
        {
            using var timer = new PeriodicTimer(PollInterval);
            do
            {
                try
                {
                    IngestOnce();
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Spool ingest cycle failed.");
                }
            }
            while (await timer.WaitForNextTickAsync(stoppingToken));
        }
        catch (OperationCanceledException)
        {
            // Expected on shutdown.
        }

        _logger.LogInformation("Agent event spool worker stopped.");
    }

    private void IngestOnce()
    {
        var sidecars = Directory.EnumerateFiles(_spoolDirectory, "*.json")
            .OrderBy(File.GetCreationTimeUtc)
            .ToList();

        foreach (var sidecar in sidecars)
        {
            OfflineEvent? offlineEvent = null;
            try
            {
                var spooled = JsonSerializer.Deserialize<SpooledEvent>(File.ReadAllText(sidecar));
                offlineEvent = spooled?.ToOfflineEvent();
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Discarding unreadable spool sidecar {File}.", sidecar);
            }

            if (offlineEvent is null)
            {
                TryDelete(sidecar); // corrupt / unknown kind → quarantine
                continue;
            }

            _eventStore.Enqueue(offlineEvent);
            _logger.LogInformation("Ingested {Kind} from spool ({Key}).", offlineEvent.Kind, offlineEvent.IdempotencyKey);
            TryDelete(sidecar); // screenshot temp images are deleted after upload, not here
        }
    }

    private void TryDelete(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Could not delete spool sidecar {File}.", path);
        }
    }
}
