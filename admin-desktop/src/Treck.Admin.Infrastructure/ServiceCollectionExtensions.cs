using Microsoft.Extensions.DependencyInjection;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Infrastructure;

public static class ServiceCollectionExtensions
{
    public static IServiceCollection AddTreckAdminInfrastructure(this IServiceCollection services)
    {
        services.AddSingleton<IAccessTokenStore, DpapiAccessTokenStore>();
        return services;
    }
}
