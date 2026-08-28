using System.Windows.Controls;
using System.Windows.Input;
using Treck.Admin.Application.ViewModels;

namespace Treck.Admin.Desktop.Views;

public partial class PresenceView : UserControl
{
    public PresenceView() => InitializeComponent();

    private void PresenceGrid_OnMouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        if (DataContext is PresenceViewModel viewModel && viewModel.OpenEmployeeCommand.CanExecute(null))
            viewModel.OpenEmployeeCommand.Execute(null);
    }
}
