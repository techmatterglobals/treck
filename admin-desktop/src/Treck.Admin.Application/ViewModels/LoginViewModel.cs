using CommunityToolkit.Mvvm.ComponentModel;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;
using Treck.Admin.Application.Services;

namespace Treck.Admin.Application.ViewModels;

public partial class LoginViewModel : ObservableObject
{
    private readonly SessionService _session;

    [ObservableProperty] private string _email = string.Empty;
    [ObservableProperty] private string? _errorMessage;
    [ObservableProperty] private bool _isBusy;

    public LoginViewModel(SessionService session) => _session = session;

    public event Action<DesktopBootstrap>? SignedIn;

    public async Task SignInAsync(string password, CancellationToken cancellationToken = default)
    {
        if (IsBusy) return;

        ErrorMessage = null;
        if (string.IsNullOrWhiteSpace(Email) || string.IsNullOrWhiteSpace(password))
        {
            ErrorMessage = "Enter your email address and password.";
            return;
        }

        try
        {
            IsBusy = true;
            var bootstrap = await _session.SignInAsync(Email, password, cancellationToken);
            SignedIn?.Invoke(bootstrap);
        }
        catch (TreckApiException exception) when (exception.IsForbidden)
        {
            ErrorMessage = "This account does not have access to Treck Admin.";
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized || exception.StatusCode == System.Net.HttpStatusCode.UnprocessableEntity)
        {
            ErrorMessage = "The email address or password is incorrect.";
        }
        catch (HttpRequestException)
        {
            ErrorMessage = "Unable to reach the Treck server.";
        }
        finally
        {
            IsBusy = false;
        }
    }
}
