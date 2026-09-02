using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;

namespace Treck.Admin.Application.ViewModels;

public partial class PresenceViewModel : ObservableObject, IPollingScreen
{
    private static readonly TimeSpan RefreshInterval = TimeSpan.FromSeconds(30);
    private readonly ITreckDesktopApi _api;
    private readonly PollingLoop _polling;
    private readonly CurrentOrganizationService _organizations;
    private CancellationTokenSource? _pollingCancellation;

    [ObservableProperty] private PresenceRow? _selectedRow;
    [ObservableProperty] private PresenceOverview? _summary;
    [ObservableProperty] private string _connectionStatus = "Connecting…";
    [ObservableProperty] private DateTimeOffset? _lastUpdatedAt;
    [ObservableProperty] private bool _isRefreshing;

    public PresenceViewModel(
        ITreckDesktopApi api,
        PollingLoop polling,
        CurrentOrganizationService organizations)
    {
        _api = api;
        _polling = polling;
        _organizations = organizations;
    }

    public ObservableCollection<PresenceRow> Rows { get; } = [];
    public event Action<long>? EmployeeRequested;
    public event Action<string>? AuthorizationLost;
    public event Action<string>? OrganizationContextLost;

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

    public void Clear()
    {
        Rows.Clear();
        Summary = null;
        SelectedRow = null;
        LastUpdatedAt = null;
        ConnectionStatus = "Select an organization";
    }

    [RelayCommand]
    private Task RefreshAsync(CancellationToken cancellationToken) => RefreshCoreAsync(cancellationToken);

    [RelayCommand]
    private void OpenEmployee()
    {
        if (SelectedRow?.EmployeeId is long employeeId) EmployeeRequested?.Invoke(employeeId);
    }

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
            var generation = _organizations.Generation;
            var result = await _api.GetPresenceAsync(cancellationToken);
            if (generation != _organizations.Generation) return;
            Rows.Clear();
            foreach (var row in result.Items) Rows.Add(row);
            Summary = result.Summary;
            LastUpdatedAt = DateTimeOffset.Now;
            ConnectionStatus = "Live";
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized)
        {
            Clear();
            Deactivate();
            AuthorizationLost?.Invoke("Your session expired. Sign in again.");
        }
        catch (TreckApiException exception) when (exception.IsForbidden || exception.IsOrganizationContextError)
        {
            Clear();
            Deactivate();
            OrganizationContextLost?.Invoke("Select an authorized organization to continue.");
        }
        catch (HttpRequestException)
        {
            ConnectionStatus = "Offline — retrying";
        }
        catch (Exception exception) when (exception is InvalidDataException or System.Text.Json.JsonException)
        {
            ConnectionStatus = "Invalid server response — retrying";
        }
        finally { IsRefreshing = false; }
    }
}
