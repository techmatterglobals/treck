using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using Treck.Admin.Application.ViewModels;

namespace Treck.Admin.Desktop.Views;

public partial class LoginView : UserControl
{
    public LoginView() => InitializeComponent();

    private async void SignIn_OnClick(object sender, RoutedEventArgs e) => await SignInAsync();

    private async void PasswordInput_OnKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            e.Handled = true;
            await SignInAsync();
        }
    }

    private async Task SignInAsync()
    {
        if (DataContext is not LoginViewModel viewModel) return;
        var password = PasswordInput.Password;
        try { await viewModel.SignInAsync(password); }
        finally
        {
            PasswordInput.Clear();
            password = string.Empty;
        }
    }
}
