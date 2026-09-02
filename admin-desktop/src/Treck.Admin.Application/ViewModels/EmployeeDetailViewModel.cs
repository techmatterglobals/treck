using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;

namespace Treck.Admin.Application.ViewModels;

public partial class EmployeeDetailViewModel : ObservableObject
{
    private readonly ITreckDesktopApi _api;
    private CancellationTokenSource? _loadCancellation;

    [ObservableProperty] private EmployeeDetail? _detail;
    [ObservableProperty] private bool _isLoading;
    [ObservableProperty] private string? _errorMessage;

    public EmployeeDetailViewModel(ITreckDesktopApi api) => _api = api;

    public event Action? BackRequested;
    public event Action<string>? AuthorizationLost;
    public event Action<string>? OrganizationContextLost;

    public void Clear()
    {
        _loadCancellation?.Cancel();
        Detail = null;
        ErrorMessage = null;
        IsLoading = false;
    }

    public async Task LoadAsync(long employeeId)
    {
        _loadCancellation?.Cancel();
        _loadCancellation?.Dispose();
        var cancellation = new CancellationTokenSource();
        _loadCancellation = cancellation;
        try
        {
            IsLoading = true;
            ErrorMessage = null;
            Detail = await _api.GetEmployeeAsync(employeeId, cancellation.Token);
        }
        catch (OperationCanceledException) when (cancellation.IsCancellationRequested) { }
        catch (TreckApiException exception) when (exception.IsUnauthorized)
        {
            AuthorizationLost?.Invoke("Your session expired. Sign in again.");
        }
        catch (TreckApiException exception) when (exception.IsOrganizationContextError)
        {
            Clear();
            OrganizationContextLost?.Invoke("Select an authorized organization to continue.");
        }
        catch (TreckApiException exception) when (exception.IsForbidden)
        {
            ErrorMessage = "You do not have permission to view this employee.";
        }
        catch (HttpRequestException)
        {
            ErrorMessage = "Unable to load employee details.";
        }
        catch (Exception exception) when (exception is InvalidDataException or System.Text.Json.JsonException)
        {
            ErrorMessage = "The server returned an invalid employee response.";
        }
        finally
        {
            if (ReferenceEquals(_loadCancellation, cancellation)) IsLoading = false;
        }
    }

    public void Cancel() => _loadCancellation?.Cancel();

    [RelayCommand]
    private void Back() => BackRequested?.Invoke();
}
