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
    private readonly CurrentOrganizationService _organizations;
    private readonly OverviewViewModel _overview;
    private readonly PresenceViewModel _presence;
    private readonly AgentHealthViewModel _agentHealth;
    private readonly EmployeeDetailViewModel _employeeDetail;

    [ObservableProperty] private string _status = "Connected";
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private string _userName = string.Empty;
    [ObservableProperty] private string _userRole = string.Empty;
    [ObservableProperty] private string _organizationName = "No organization selected";
    [ObservableProperty] private DesktopOrganization? _selectedOrganization;
    [ObservableProperty] private ObservableObject? _currentPage;
    [ObservableProperty] private NavigationItem? _selectedNavigation;
    [ObservableProperty] private bool _isSwitchingOrganization;
    private bool _suppressSelectionChange;

    public ShellViewModel(
        ITreckDesktopApi api,
        SessionService session,
        CurrentOrganizationService organizations,
        OverviewViewModel overview,
        PresenceViewModel presence,
        AgentHealthViewModel agentHealth,
        EmployeeDetailViewModel employeeDetail)
    {
        _api = api;
        _session = session;
        _organizations = organizations;
        _overview = overview;
        _presence = presence;
        _agentHealth = agentHealth;
        _employeeDetail = employeeDetail;
        _overview.AuthorizationLost += OnAuthorizationLost;
        _overview.OrganizationContextLost += OnOrganizationContextLost;
        _presence.AuthorizationLost += OnAuthorizationLost;
        _presence.OrganizationContextLost += OnOrganizationContextLost;
        _agentHealth.AuthorizationLost += OnAuthorizationLost;
        _agentHealth.OrganizationContextLost += OnOrganizationContextLost;
        _presence.EmployeeRequested += OnEmployeeRequested;
        _employeeDetail.AuthorizationLost += OnAuthorizationLost;
        _employeeDetail.OrganizationContextLost += OnOrganizationContextLost;
        _employeeDetail.BackRequested += OnEmployeeDetailBackRequested;
    }

    public ObservableCollection<NavigationItem> Navigation { get; } = [];
    public ObservableCollection<DesktopOrganization> Organizations { get; } = [];
    public event Action<string?>? SignedOut;

    public void Initialize(DesktopBootstrap bootstrap)
    {
        UserName = bootstrap.User.Name;
        ReplaceOrganizations(bootstrap);
        ApplySelectedOrganization();
        Status = SelectedOrganization is null ? "Select an organization to continue." : "Connected";
        BuildNavigation(bootstrap);
        if (Navigation.Any(item => item.Key == "overview"))
        {
            Navigate(Navigation.First(item => item.Key == "overview"));
        }
        else
        {
            CurrentPage = new MessageViewModel("Organization required", "Select an authorized organization before loading tenant data.");
        }
    }

    [RelayCommand]
    private async Task RefreshAsync(CancellationToken cancellationToken)
    {
        if (IsBusy) return;

        try
        {
            IsBusy = true;
            var bootstrap = await _api.GetBootstrapAsync(cancellationToken);
            await _organizations.RevalidateAsync(bootstrap, cancellationToken);
            Initialize(bootstrap);
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

    partial void OnSelectedOrganizationChanged(DesktopOrganization? value)
    {
        if (_suppressSelectionChange || value is null || value.Id == _organizations.SelectedOrganizationId) return;
        _ = SwitchOrganizationAsync(value, CancellationToken.None);
    }

    private async Task SwitchOrganizationAsync(DesktopOrganization organization, CancellationToken cancellationToken)
    {
        if (IsSwitchingOrganization) return;

        try
        {
            IsSwitchingOrganization = true;
            Status = "Switching organization...";
            DeactivateCurrentPage();
            ClearTenantData();
            Navigation.Clear();
            SelectedNavigation = null;

            await _organizations.SelectAsync(organization, cancellationToken);
            var bootstrap = await _api.GetBootstrapAsync(cancellationToken);
            if (!bootstrap.Organizations.Any(candidate => candidate.Id == organization.Id))
            {
                await _organizations.ClearSelectionAsync(cancellationToken);
            }

            Initialize(bootstrap);
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized)
        {
            await CloseSessionAsync("Your session expired. Sign in again.");
        }
        catch (TreckApiException exception) when (exception.IsForbidden || exception.IsOrganizationContextError)
        {
            ClearTenantData();
            Status = "This organization is no longer available.";
        }
        catch (HttpRequestException)
        {
            ClearTenantData();
            Status = "Unable to reach the Treck server.";
        }
        finally
        {
            IsSwitchingOrganization = false;
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
            "health" => _agentHealth,
            _ => new MessageViewModel(item.Label, "This authorized module will be added in a later monitoring milestone."),
        };

        if (item.Key is not ("overview" or "presence" or "health"))
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

    private async void OnOrganizationContextLost(string message)
    {
        DeactivateCurrentPage();
        ClearTenantData();
        await _organizations.ClearSelectionAsync(CancellationToken.None);
        Status = message;
        CurrentPage = new MessageViewModel("Organization required", message);
        try
        {
            var bootstrap = await _api.GetBootstrapAsync(CancellationToken.None);
            await _organizations.RevalidateAsync(bootstrap, CancellationToken.None);
            Initialize(bootstrap);
        }
        catch (HttpRequestException)
        {
            Status = "Unable to refresh organizations.";
        }
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

    private void ClearTenantData()
    {
        _overview.Clear();
        _presence.Clear();
        _agentHealth.Clear();
        _employeeDetail.Clear();
    }

    private void BuildNavigation(DesktopBootstrap bootstrap)
    {
        Navigation.Clear();
        if (SelectedOrganization is null) return;

        var features = SelectedOrganization.Features;
        var permissions = SelectedOrganization.Permissions;
        Navigation.Add(new NavigationItem("overview", "Overview"));
        if (features.Presence) Navigation.Add(new NavigationItem("presence", "Live presence"));
        if (features.Attendance && permissions.Contains("view attendance"))
            Navigation.Add(new NavigationItem("attendance", "Attendance"));
        if (features.ApplicationUsage) Navigation.Add(new NavigationItem("applications", "Applications"));
        if (features.Screenshots) Navigation.Add(new NavigationItem("screenshots", "Screenshots"));
        if (features.Downloads) Navigation.Add(new NavigationItem("downloads", "Downloads"));
        if (features.Reports && permissions.Contains("view reports"))
            Navigation.Add(new NavigationItem("reports", "Reports"));
        if (features.AgentHealth) Navigation.Add(new NavigationItem("health", "Agent health"));
    }

    private void ReplaceOrganizations(DesktopBootstrap bootstrap)
    {
        _suppressSelectionChange = true;
        try
        {
            Organizations.Clear();
            foreach (var organization in bootstrap.Organizations) Organizations.Add(organization);
            SelectedOrganization = _organizations.Selected is null
                ? null
                : Organizations.FirstOrDefault(organization => organization.Id == _organizations.Selected.Id);
        }
        finally
        {
            _suppressSelectionChange = false;
        }
    }

    private void ApplySelectedOrganization()
    {
        if (SelectedOrganization is null)
        {
            OrganizationName = "No organization selected";
            UserRole = "authorized user";
            return;
        }

        OrganizationName = SelectedOrganization.Name;
        UserRole = SelectedOrganization.Role;
    }
}
