using System.Net.Http.Json;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Models;

namespace Treck.Admin.Api;

public sealed class TreckDesktopApi : ITreckDesktopApi
{
    private readonly HttpClient _client;

    public TreckDesktopApi(HttpClient client)
    {
        _client = client;
    }

    public Task<DesktopBootstrap> GetBootstrapAsync(CancellationToken cancellationToken = default) =>
        GetDataAsync<DesktopBootstrap>("api/v1/desktop/bootstrap", cancellationToken);

    public Task<DesktopOverview> GetOverviewAsync(CancellationToken cancellationToken = default) =>
        GetDataAsync<DesktopOverview>("api/v1/desktop/overview", cancellationToken);

    public Task<DesktopPresence> GetPresenceAsync(CancellationToken cancellationToken = default) =>
        GetDataAsync<DesktopPresence>("api/v1/desktop/presence", cancellationToken);

    public Task<DesktopAgentHealth> GetAgentHealthAsync(CancellationToken cancellationToken = default) =>
        GetDataAsync<DesktopAgentHealth>("api/v1/desktop/agent-health", cancellationToken);

    public Task<EmployeeDetail> GetEmployeeAsync(long employeeId, CancellationToken cancellationToken = default) =>
        GetDataAsync<EmployeeDetail>($"api/v1/desktop/employees/{employeeId}", cancellationToken);

    private async Task<T> GetDataAsync<T>(string uri, CancellationToken cancellationToken)
    {
        using var response = await _client.GetAsync(uri, cancellationToken);
        await ApiSerialization.EnsureSuccessAsync(response, cancellationToken);

        var envelope = await response.Content.ReadFromJsonAsync<ApiEnvelope<T>>(ApiSerialization.JsonOptions, cancellationToken);
        return envelope is null
            ? throw new InvalidDataException("The Treck server returned an empty response.")
            : envelope.Data;
    }

    private sealed record ApiEnvelope<T>(T Data);
}
