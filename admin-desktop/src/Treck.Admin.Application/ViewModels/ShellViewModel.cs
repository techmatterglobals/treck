using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;

namespace Treck.Admin.Application.ViewModels;

public partial class ShellViewModel : ObservableObject
{
    private readonly ITreckDesktopApi _api;
    private readonly SessionService _session;
    private readonly OverviewViewModel _overview;
    private readonly PresenceViewModel _presence;
    private readonly EmployeeDetailViewModel _employeeDetail;

    [ObservableProperty] private string _status = "Connected";
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private string _userName = string.Empty;
    [ObservableProperty] private string _userRole = string.Empty;
    [ObservableProperty] private ObservableObject? _currentPage;
    [ObservableProperty] private NavigationItem? _selectedNavigation;

    public ShellViewModel(
        ITreckDesktopApi api,
        SessionService session,
        OverviewViewModel overview,
        PresenceViewModel presence,
        EmployeeDetailViewModel employeeDetail)
    {
        _api = api;
        _session = session;
        _overview = overview;
        _presence = presence;
        _employeeDetail = employeeDetail;
        _overview.AuthorizationLost += OnAuthorizationLost;
        _presence.AuthorizationLost += OnAuthorizationLost;
        _presence.EmployeeRequested += OnEmployeeRequested;
        _employeeDetail.AuthorizationLost += OnAuthorizationLost;
        _employeeDetail.BackRequested += OnEmployeeDetailBackRequested;
    }

    public ObservableCollection<NavigationItem> Navigation { get; } = [];
    public event Action<string?>? SignedOut;

    public void Initialize(DesktopBootstrap bootstrap)
    {
        UserName = bootstrap.User.Name;
        UserRole = bootstrap.Roles.FirstOrDefault() ?? "authorized user";
        Status = "Connected";
        BuildNavigation(bootstrap);
        Navigate(Navigation.First(item => item.Key == "overview"));
    }

    [RelayCommand]
    private async Task RefreshAsync(CancellationToken cancellationToken)
    {
        if (IsBusy) return;

        try
        {
            IsBusy = true;
            Initialize(await _api.GetBootstrapAsync(cancellationToken));
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized)
        {
            Status = "Your session has expired.";
            await CloseSessionAsync("Your session expired. Sign in again.");
        }
        catch (TreckApiException exception) when (exception.IsForbidden)
        {
            Status = "Your account no longer has permission to use Treck Admin.";
            await CloseSessionAsync("This account no longer has access to Treck Admin.");
        }
        catch (HttpRequestException)
        {
            Status = "Unable to reach the Treck server.";
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    private async Task SignOutAsync(CancellationToken cancellationToken)
    {
        if (IsBusy) return;

        IsBusy = true;
        try
        {
            DeactivateCurrentPage();
            await _session.SignOutAsync(cancellationToken);
            SignedOut?.Invoke(null);
        }
        catch (HttpRequestException)
        {
            SignedOut?.Invoke(null);
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    private void Navigate(NavigationItem item)
    {
        DeactivateCurrentPage();
        SelectedNavigation = item;
        CurrentPage = item.Key switch
        {
            "overview" => _overview,
            "presence" => _presence,
            _ => new MessageViewModel(item.Label, "This authorized module will be added in a later monitoring milestone."),
        };

        if (item.Key is not ("overview" or "presence"))
        {
            Status = $"{item.Label} will be added in a later monitoring milestone.";
            return;
        }

        Status = "Connected";
        (CurrentPage as IPollingScreen)?.Activate();
    }

    private async void OnEmployeeRequested(long employeeId)
    {
        DeactivateCurrentPage();
        CurrentPage = _employeeDetail;
        await _employeeDetail.LoadAsync(employeeId);
    }

    private void OnEmployeeDetailBackRequested()
    {
        _employeeDetail.Cancel();
        var presenceItem = Navigation.First(item => item.Key == "presence");
        Navigate(presenceItem);
    }

    private async void OnAuthorizationLost(string message)
    {
        await CloseSessionAsync(message);
    }

    private async Task CloseSessionAsync(string message)
    {
        DeactivateCurrentPage();
        try { await _session.SignOutAsync(CancellationToken.None); }
        catch (HttpRequestException) { }
        finally { SignedOut?.Invoke(message); }
    }

    private void DeactivateCurrentPage()
    {
        (CurrentPage as IPollingScreen)?.Deactivate();
        if (CurrentPage is EmployeeDetailViewModel detail) detail.Cancel();
    }

    private void BuildNavigation(DesktopBootstrap bootstrap)
    {
        Navigation.Clear();
        Navigation.Add(new NavigationItem("overview", "Overview"));
        if (bootstrap.Features.Presence) Navigation.Add(new NavigationItem("presence", "Live presence"));
        if (bootstrap.Features.Attendance && bootstrap.Permissions.Contains("view attendance"))
            Navigation.Add(new NavigationItem("attendance", "Attendance"));
        if (bootstrap.Features.ApplicationUsage) Navigation.Add(new NavigationItem("applications", "Applications"));
        if (bootstrap.Features.Screenshots) Navigation.Add(new NavigationItem("screenshots", "Screenshots"));
        if (bootstrap.Features.Downloads) Navigation.Add(new NavigationItem("downloads", "Downloads"));
        if (bootstrap.Features.Reports && bootstrap.Permissions.Contains("view reports"))
            Navigation.Add(new NavigationItem("reports", "Reports"));
        if (bootstrap.Features.AgentHealth) Navigation.Add(new NavigationItem("health", "Agent health"));
    }
}
