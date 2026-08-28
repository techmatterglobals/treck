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

    [ObservableProperty] private string _status = "Connected";
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private string _userName = string.Empty;
    [ObservableProperty] private string _userRole = string.Empty;

    public ShellViewModel(ITreckDesktopApi api, SessionService session)
    {
        _api = api;
        _session = session;
    }

    public ObservableCollection<NavigationItem> Navigation { get; } = [];
    public event Action<string?>? SignedOut;

    public void Initialize(DesktopBootstrap bootstrap)
    {
        UserName = bootstrap.User.Name;
        UserRole = bootstrap.Roles.FirstOrDefault() ?? "authorized user";
        Status = "Connected";
        BuildNavigation(bootstrap);
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
            await _session.SignOutAsync(CancellationToken.None);
            SignedOut?.Invoke("Your session expired. Sign in again.");
        }
        catch (TreckApiException exception) when (exception.IsForbidden)
        {
            Status = "Your account no longer has permission to use Treck Admin.";
            await _session.SignOutAsync(CancellationToken.None);
            SignedOut?.Invoke("This account no longer has access to Treck Admin.");
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
