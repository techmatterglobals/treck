using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Offline;

namespace Treck.Agent.Sync;

/// <summary>
/// Background loop that drains the offline queue. Runs a sync cycle every
/// <c>SyncIntervalSeconds</c>; on a failed/blocked cycle it backs off
/// exponentially up to <c>MaxBackoffSeconds</c>, resetting on progress.
/// </summary>
public sealed class SyncWorker : BackgroundService
{
    private readonly ISyncService _syncService;
    private readonly IOfflineEventStore _store;
    private readonly OfflineStoreOptions _options;
    private readonly ILogger<SyncWorker> _logger;

    public SyncWorker(
        ISyncService syncService,
        IOfflineEventStore store,
        IOptions<OfflineStoreOptions> options,
        ILogger<SyncWorker> logger)
    {
        _syncService = syncService;
        _store = store;
        _options = options.Value;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _store.Initialize();
        _logger.LogInformation("Sync worker started (interval={IntervalSeconds}s).", _options.SyncIntervalSeconds);

        var baseDelay = TimeSpan.FromSeconds(_options.SyncIntervalSeconds);
        var maxDelay = TimeSpan.FromSeconds(_options.MaxBackoffSeconds);
        var delay = baseDelay;

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await Task.Delay(delay, stoppingToken);

                var result = await _syncService.SyncPendingAsync(stoppingToken);

                // Back off only when work remains but nothing was uploaded.
                if (result.Uploaded == 0 && result.Failed > 0)
                {
                    delay = TimeSpan.FromTicks(Math.Min(delay.Ticks * 2, maxDelay.Ticks));
                    _logger.LogDebug("Sync made no progress; backing off to {DelaySeconds}s.", delay.TotalSeconds);
                }
                else
                {
                    delay = baseDelay;
                }
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (Exception ex)
            {
                delay = TimeSpan.FromTicks(Math.Min(delay.Ticks * 2, maxDelay.Ticks));
                _logger.LogError(ex, "Sync cycle failed; backing off to {DelaySeconds}s.", delay.TotalSeconds);
            }
        }

        _logger.LogInformation("Sync worker stopped.");
    }
}
