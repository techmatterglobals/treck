using Microsoft.Extensions.DependencyInjection;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Api;

public static class ServiceCollectionExtensions
{
    public static IServiceCollection AddTreckDesktopApi(this IServiceCollection services, Uri baseAddress)
    {
        services.AddTransient<AccessTokenHandler>();
        services.AddHttpClient<ITreckDesktopApi, TreckDesktopApi>(client =>
        {
            client.BaseAddress = baseAddress;
            client.Timeout = TimeSpan.FromSeconds(30);
            client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
        }).AddHttpMessageHandler<AccessTokenHandler>();

        services.AddHttpClient<ITreckAuthenticationApi, TreckAuthenticationApi>(client =>
        {
            client.BaseAddress = baseAddress;
            client.Timeout = TimeSpan.FromSeconds(30);
            client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
        }).AddHttpMessageHandler<AccessTokenHandler>();

        return services;
    }
}
