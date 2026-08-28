using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;

namespace Treck.Admin.Application.ViewModels;

public partial class OverviewViewModel : ObservableObject, IPollingScreen
{
    private static readonly TimeSpan RefreshInterval = TimeSpan.FromSeconds(60);
    private readonly ITreckDesktopApi _api;
    private readonly PollingLoop _polling;
    private CancellationTokenSource? _pollingCancellation;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(TotalEmployees))]
    [NotifyPropertyChangedFor(nameof(PresentEmployees))]
    [NotifyPropertyChangedFor(nameof(ActiveComputers))]
    [NotifyPropertyChangedFor(nameof(IdleComputers))]
    [NotifyPropertyChangedFor(nameof(ActiveTime))]
    [NotifyPropertyChangedFor(nameof(IdleTime))]
    [NotifyPropertyChangedFor(nameof(ActivePercent))]
    private DesktopOverview? _overview;

    [ObservableProperty] private string _connectionStatus = "Connecting…";
    [ObservableProperty] private DateTimeOffset? _lastUpdatedAt;
    [ObservableProperty] private bool _isRefreshing;

    public OverviewViewModel(ITreckDesktopApi api, PollingLoop polling)
    {
        _api = api;
        _polling = polling;
    }

    public int TotalEmployees => Overview?.Employees.Total ?? 0;
    public int PresentEmployees => Overview?.Employees.Present ?? 0;
    public int ActiveComputers => Overview?.Presence.Active ?? 0;
    public int IdleComputers => Overview?.Presence.Idle ?? 0;
    public string ActiveTime => FormatDuration(Overview?.Activity.ActiveSeconds ?? 0);
    public string IdleTime => FormatDuration(Overview?.Activity.IdleSeconds ?? 0);
    public string ActivePercent => $"{Overview?.Activity.ActivePercent ?? 0:0.#}%";
    public event Action<string>? AuthorizationLost;

    public void Activate()
    {
        if (_pollingCancellation is not null) return;
        _pollingCancellation = new CancellationTokenSource();
        _ = RunPollingAsync(_pollingCancellation.Token);
    }

    public void Deactivate()
    {
        _pollingCancellation?.Cancel();
        _pollingCancellation?.Dispose();
        _pollingCancellation = null;
    }

    [RelayCommand]
    private Task RefreshAsync(CancellationToken cancellationToken) => RefreshCoreAsync(cancellationToken);

    private async Task RunPollingAsync(CancellationToken cancellationToken)
    {
        try { await _polling.RunAsync(RefreshInterval, RefreshCoreAsync, cancellationToken); }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
    }

    private async Task RefreshCoreAsync(CancellationToken cancellationToken)
    {
        if (IsRefreshing) return;
        try
        {
            IsRefreshing = true;
            Overview = await _api.GetOverviewAsync(cancellationToken);
            LastUpdatedAt = DateTimeOffset.Now;
            ConnectionStatus = "Live";
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized || exception.IsForbidden)
        {
            Deactivate();
            AuthorizationLost?.Invoke(exception.IsForbidden
                ? "This account no longer has access to Treck Admin."
                : "Your session expired. Sign in again.");
        }
        catch (HttpRequestException)
        {
            ConnectionStatus = "Offline — retrying";
        }
        catch (Exception exception) when (exception is InvalidDataException or System.Text.Json.JsonException)
        {
            ConnectionStatus = "Invalid server response — retrying";
        }
        finally
        {
            IsRefreshing = false;
        }
    }

    private static string FormatDuration(long seconds) =>
        $"{seconds / 3600}h {(seconds % 3600) / 60:00}m";
}
