using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Applications;
using Treck.Agent.Configuration;
using Treck.Agent.Offline;

namespace Treck.Agent.Spooling;

/// <summary>
/// Runs Phase 7 application-usage tracking INSIDE the interactive capture helper.
/// <c>GetForegroundWindow</c> and WinEvent hooks only see the caller's desktop, so
/// this must run in the user session, not the Session-0 service. Completed usage
/// sessions are written to the spool as <c>app_usage</c> events; the service
/// ingests and uploads them through the existing pipeline.
/// </summary>
public sealed class ApplicationUsageSpoolForwarder : IHostedService
{
    private readonly ILogger<ApplicationUsageSpoolForwarder> _logger;
    private readonly ApplicationTrackingOptions _options;
    private readonly IApplicationTracker _tracker;
    private readonly IApplicationSessionManager _sessions;
    private readonly IAgentEventSpool _spool;
    private readonly EventSource _source;

    public ApplicationUsageSpoolForwarder(
        ILogger<ApplicationUsageSpoolForwarder> logger,
        IOptions<ApplicationTrackingOptions> options,
        IApplicationTracker tracker,
        IApplicationSessionManager sessions,
        IAgentEventSpool spool,
        EventSource source)
    {
        _logger = logger;
        _options = options.Value;
        _tracker = tracker;
        _sessions = sessions;
        _spool = spool;
        _source = source;
    }

    public Task StartAsync(CancellationToken cancellationToken)
    {
        if (!_options.Enabled)
        {
            _logger.LogInformation("Application tracking disabled by configuration.");
            return Task.CompletedTask;
        }

        _sessions.SessionCompleted += OnSessionCompleted;
        _tracker.ApplicationChanged += OnApplicationChanged;
        _tracker.Start();
        _logger.LogInformation("Application-usage collection running in the interactive helper.");
        return Task.CompletedTask;
    }

    public Task StopAsync(CancellationToken cancellationToken)
    {
        if (!_options.Enabled)
        {
            return Task.CompletedTask;
        }

        _tracker.Stop();
        _tracker.ApplicationChanged -= OnApplicationChanged;
        _sessions.Flush(DateTimeOffset.UtcNow); // ship the session open at shutdown
        _sessions.SessionCompleted -= OnSessionCompleted;
        return Task.CompletedTask;
    }

    private void OnApplicationChanged(object? sender, ApplicationChangedEventArgs change)
        => _sessions.Track(change.Application, change.TimestampUtc);

    private void OnSessionCompleted(object? sender, ApplicationUsageEvent session)
    {
        try
        {
            var json = SourceStamp.Apply(JsonSerializer.Serialize(session), _source);
            _spool.Submit(OfflineEvent.Create(OfflineEventKind.AppUsage, json, session.EndedAt));
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Failed to spool application-usage session.");
        }
    }
}
