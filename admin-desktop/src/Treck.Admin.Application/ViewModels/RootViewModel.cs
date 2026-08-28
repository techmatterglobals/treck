using CommunityToolkit.Mvvm.ComponentModel;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;

namespace Treck.Admin.Application.ViewModels;

public partial class RootViewModel : ObservableObject
{
    private readonly SessionService _session;
    private readonly LoginViewModel _login;
    private readonly ShellViewModel _shell;

    [ObservableProperty] private ObservableObject _currentViewModel;

    public RootViewModel(SessionService session, LoginViewModel login, ShellViewModel shell)
    {
        _session = session;
        _login = login;
        _shell = shell;
        _currentViewModel = login;
        _login.SignedIn += OnSignedIn;
        _shell.SignedOut += OnSignedOut;
    }

    public async Task InitializeAsync(CancellationToken cancellationToken = default)
    {
        try
        {
            var bootstrap = await _session.RestoreAsync(cancellationToken);
            if (bootstrap is not null) ShowShell(bootstrap);
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized)
        {
            _login.ErrorMessage = "Your previous session expired. Sign in again.";
        }
        catch (TreckApiException exception) when (exception.IsForbidden)
        {
            _login.ErrorMessage = "This account no longer has access to Treck Admin.";
        }
        catch (HttpRequestException)
        {
            _login.ErrorMessage = "Unable to restore your session because the server is unavailable.";
        }
    }

    private void OnSignedIn(DesktopBootstrap bootstrap) => ShowShell(bootstrap);

    private void OnSignedOut(string? message)
    {
        _login.ErrorMessage = message;
        CurrentViewModel = _login;
    }

    private void ShowShell(DesktopBootstrap bootstrap)
    {
        _shell.Initialize(bootstrap);
        CurrentViewModel = _shell;
    }
}
