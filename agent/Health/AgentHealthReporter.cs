using System.Reflection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Treck.Agent.Api;
using Treck.Agent.Configuration;
using Treck.Agent.Models;
using Treck.Agent.Offline;
using Treck.Agent.Services;

namespace Treck.Agent.Health;

public sealed class AgentHealthReporter : BackgroundService
{
    private static readonly string AgentVersion =
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    private readonly IDeviceRegistrationService _registration;
    private readonly ITreckApiClient _api;
    private readonly IAgentPolicyCache _policyCache;
    private readonly IOfflineEventStore _store;
    private readonly AgentHealthState _state;
    private readonly ILogger<AgentHealthReporter> _logger;

    public AgentHealthReporter(
        IDeviceRegistrationService registration,
        ITreckApiClient api,
        IAgentPolicyCache policyCache,
        IOfflineEventStore store,
        AgentHealthState state,
        ILogger<AgentHealthReporter> logger)
    {
        _registration = registration;
        _api = api;
        _policyCache = policyCache;
        _store = store;
        _state = state;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _store.Initialize();
        var interval = TimeSpan.FromSeconds(60);
        _logger.LogInformation("Agent health reporter started (interval={Seconds}s).", interval.TotalSeconds);

        try
        {
            using var timer = new PeriodicTimer(interval);
            do
            {
                await ReportOnceAsync(stoppingToken);
            }
            while (await timer.WaitForNextTickAsync(stoppingToken));
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
        {
        }

        _logger.LogInformation("Agent health reporter stopped.");
    }

    private async Task ReportOnceAsync(CancellationToken cancellationToken)
    {
        try
        {
            var token = await _registration.EnsureRegisteredAsync(cancellationToken);
            var configRevision = _policyCache.TryLoad()?.Revision ?? "unknown";

            try
            {
                var config = await _api.GetAgentConfigAsync(token, cancellationToken);
                _policyCache.Save(config);
                configRevision = config.Revision;
            }
            catch (UnauthorizedApiException)
            {
                await TryReRegisterAsync(cancellationToken);
                _state.MarkError("unauthorized");
                return;
            }
            catch (Exception ex)
            {
                _state.MarkError("config_fetch_failed");
                _logger.LogWarning(ex, "Could not refresh agent config; reporting with last-known-good policy.");
            }

            var snapshot = _state.Current();
            var request = new AgentHealthReportRequest(
                AgentVersion: AgentVersion,
                ConfigRevision: configRevision,
                PendingEventCount: _store.CountPending(),
                HelperRunning: snapshot.HelperRunning,
                HelperSessionId: snapshot.HelperSessionId,
                ServiceStartedAt: snapshot.ServiceStartedAt,
                LastCaptureAt: snapshot.LastCaptureAt,
                LastSuccessfulSyncAt: snapshot.LastSuccessfulSyncAt,
                LastErrorCategory: snapshot.LastErrorCategory,
                ReportTime: DateTimeOffset.UtcNow);

            var accepted = await _api.ReportHealthAsync(token, request, cancellationToken);
            if (!accepted)
            {
                _state.MarkError("health_rejected");
                _logger.LogWarning("Health report was not accepted by the server.");
            }
        }
        catch (UnauthorizedApiException)
        {
            await TryReRegisterAsync(cancellationToken);
            _state.MarkError("unauthorized");
        }
        catch (Exception ex) when (!cancellationToken.IsCancellationRequested)
        {
            _state.MarkError(ex.GetType().Name);
            _logger.LogWarning(ex, "Could not report agent health.");
        }
    }

    private async Task TryReRegisterAsync(CancellationToken cancellationToken)
    {
        try
        {
            await _registration.ReRegisterAsync(cancellationToken);
        }
        catch (Exception ex) when (!cancellationToken.IsCancellationRequested)
        {
            _state.MarkError("registration_failed");
            _logger.LogWarning(ex, "Could not re-register after an unauthorized agent response.");
        }
    }
}
