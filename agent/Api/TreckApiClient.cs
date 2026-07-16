using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Models;

namespace Treck.Agent.Api;

/// <summary>
/// Typed HttpClient wrapper. Registered via IHttpClientFactory (with Polly retry
/// + TLS-validating handler in Program.cs). Single responsibility: serialize the
/// request, POST it, and map the JSON envelope to a typed DTO.
/// </summary>
public sealed class TreckApiClient : ITreckApiClient
{
    public static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
    };

    private readonly HttpClient _http;
    private readonly ILogger<TreckApiClient> _logger;

    public TreckApiClient(HttpClient http, ILogger<TreckApiClient> logger)
    {
        _http = http;
        _logger = logger;
    }

    public async Task<RegisterDeviceResponse> RegisterDeviceAsync(
        RegisterDeviceRequest request,
        CancellationToken cancellationToken)
    {
        using var response = await _http.PostAsJsonAsync("api/agent/register", request, JsonOptions, cancellationToken);

        if (!response.IsSuccessStatusCode)
        {
            _logger.LogError(
                "Device registration HTTP {Status} for device {DeviceUuid}",
                (int)response.StatusCode,
                request.DeviceUuid);

            throw ApiException.FromStatus((int)response.StatusCode);
        }

        var envelope = await response.Content.ReadFromJsonAsync<ApiEnvelope<RegisterDeviceResponse>>(JsonOptions, cancellationToken);

        return envelope?.Data
            ?? throw new ApiException("Registration succeeded but the response body was empty.");
    }
}
