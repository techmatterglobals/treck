using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using System.Windows;
using Treck.Admin.Api;
using Treck.Admin.Application.ViewModels;
using Treck.Admin.Infrastructure;

namespace Treck.Admin.Desktop;

public partial class App : System.Windows.Application
{
    private IHost? _host;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        _host = Host.CreateDefaultBuilder()
            .ConfigureAppConfiguration(builder => builder.AddJsonFile("appsettings.json", optional: false))
            .ConfigureServices((context, services) =>
            {
                var baseUrl = context.Configuration["Treck:BaseUrl"]
                    ?? throw new InvalidOperationException("Treck:BaseUrl is required.");
                services.AddTreckDesktopApi(new Uri(baseUrl, UriKind.Absolute));
                services.AddTreckAdminInfrastructure();
                services.AddTransient<ShellViewModel>();
                services.AddTransient<MainWindow>();
            })
            .Build();

        await _host.StartAsync();
        _host.Services.GetRequiredService<MainWindow>().Show();
    }

    protected override async void OnExit(ExitEventArgs e)
    {
        if (_host is not null)
        {
            await _host.StopAsync(TimeSpan.FromSeconds(5));
            _host.Dispose();
        }

        base.OnExit(e);
    }
}
