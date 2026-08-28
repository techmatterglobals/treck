using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Application.ViewModels;

public partial class ShellViewModel : ObservableObject
{
    private readonly ITreckDesktopApi _api;

    [ObservableProperty]
    private string _status = "Ready to connect";

    [ObservableProperty]
    private bool _isBusy;

    public ShellViewModel(ITreckDesktopApi api)
    {
        _api = api;
    }

    [RelayCommand]
    private async Task RefreshAsync(CancellationToken cancellationToken)
    {
        if (IsBusy)
        {
            return;
        }

        try
        {
            IsBusy = true;
            var bootstrap = await _api.GetBootstrapAsync(cancellationToken);
            Status = $"Connected as {bootstrap.User.Name}";
        }
        catch (HttpRequestException)
        {
            Status = "Unable to reach the Treck server";
        }
        finally
        {
            IsBusy = false;
        }
    }
}
