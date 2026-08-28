using Microsoft.Extensions.DependencyInjection;
using Treck.Admin.Application.Services;
using Treck.Admin.Application.ViewModels;

namespace Treck.Admin.Application;

public static class ServiceCollectionExtensions
{
    public static IServiceCollection AddTreckAdminApplication(this IServiceCollection services)
    {
        services.AddSingleton<SessionService>();
        services.AddSingleton<LoginViewModel>();
        services.AddSingleton<ShellViewModel>();
        services.AddSingleton<RootViewModel>();
        return services;
    }
}
