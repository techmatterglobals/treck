using System.Windows;
using Treck.Admin.Application.ViewModels;

namespace Treck.Admin.Desktop;

public partial class MainWindow : Window
{
    public MainWindow(RootViewModel viewModel)
    {
        InitializeComponent();
        DataContext = viewModel;
    }
}
