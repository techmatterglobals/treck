using CommunityToolkit.Mvvm.ComponentModel;

namespace Treck.Admin.Application.ViewModels;

public sealed class MessageViewModel : ObservableObject
{
    public MessageViewModel(string title, string message)
    {
        Title = title;
        Message = message;
    }

    public string Title { get; }
    public string Message { get; }
}
