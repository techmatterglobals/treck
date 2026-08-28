using System.Net.Http.Json;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Models;

namespace Treck.Admin.Api;

public sealed class TreckAuthenticationApi : ITreckAuthenticationApi
{
    private readonly HttpClient _client;

    public TreckAuthenticationApi(HttpClient client) => _client = client;

    public async Task<LoginSession> LoginAsync(string email, string password, string deviceName,
        CancellationToken cancellationToken = default)
    {
        using var response = await _client.PostAsJsonAsync("api/v1/auth/login",
            new { email, password, device_name = deviceName }, ApiSerialization.JsonOptions, cancellationToken);
        await ApiSerialization.EnsureSuccessAsync(response, cancellationToken);
        return await response.Content.ReadFromJsonAsync<LoginSession>(ApiSerialization.JsonOptions, cancellationToken)
            ?? throw new InvalidDataException("The Treck server returned an empty login response.");
    }

    public async Task LogoutAsync(CancellationToken cancellationToken = default)
    {
        using var response = await _client.PostAsync("api/v1/auth/logout", null, cancellationToken);
        await ApiSerialization.EnsureSuccessAsync(response, cancellationToken);
    }
}
