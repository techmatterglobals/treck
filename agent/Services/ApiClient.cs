using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;
using Treck.Agent.Models;

namespace Treck.Agent.Services;

/// <summary>
/// Typed HttpClient for the Laravel agent API (doc 13). Attaches the Bearer
/// token, serializes snake_case JSON, and retries transient failures with
/// exponential backoff. Throws <see cref="UnauthorizedAccessException"/> on 401
/// so the caller can re-register.
/// </summary>
public sealed class ApiClient
{
    private static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
    };

    private readonly HttpClient _http;
    private readonly TokenStore _tokens;
    private readonly AgentOptions _options;

    public ApiClient(HttpClient http, TokenStore tokens, IOptions<AgentOptions> options)
    {
        _options = options.Value;
        _tokens = tokens;

        _http = http;
        _http.BaseAddress = new Uri(_options.BaseUrl);
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
    }

    public async Task<RegisterData> RegisterAsync(string computerName, string os, CancellationToken ct)
    {
        var body = new RegisterRequest(
            _options.ProvisioningKey,
            _tokens.DeviceUuid,
            _options.EmployeeCode,
            computerName,
            os,
            AgentVersion());

        var envelope = await PostAsync<ApiEnvelope<RegisterData>>("/api/agent/register", body, authorize: false, ct);
        return envelope.Data;
    }

    public async Task<long> LoginAsync(string computerName, CancellationToken ct)
    {
        var body = new LoginRequest(computerName);
        var envelope = await PostAsync<ApiEnvelope<LoginData>>("/api/agent/login", body, authorize: true, ct);
        return envelope.Data.SessionId;
    }

    public Task SendActivityAsync(long sessionId, int active, int idle, AgentStatus status, CancellationToken ct)
    {
        var body = new ActivityRequest(sessionId, active, idle, status.ToString().ToLowerInvariant());
        return PostAsync<ApiEnvelope<object>>("/api/activity", body, authorize: true, ct);
    }

    public Task LogoutAsync(long sessionId, int active, int idle, CancellationToken ct)
    {
        var body = new LogoutRequest(sessionId, active, idle);
        return PostAsync<ApiEnvelope<object>>("/api/agent/logout", body, authorize: true, ct);
    }

    private async Task<T> PostAsync<T>(string path, object body, bool authorize, CancellationToken ct)
    {
        Exception? last = null;

        for (var attempt = 0; attempt <= _options.MaxRetries; attempt++)
        {
            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Post, path)
                {
                    Content = JsonContent.Create(body, options: Json),
                };

                if (authorize)
                {
                    request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _tokens.Token);
                }

                using var response = await _http.SendAsync(request, ct);

                if (response.StatusCode == HttpStatusCode.Unauthorized)
                {
                    throw new UnauthorizedAccessException("Device token rejected (401).");
                }

                response.EnsureSuccessStatusCode();

                var payload = await response.Content.ReadFromJsonAsync<T>(Json, ct);
                return payload ?? throw new InvalidOperationException("Empty API response.");
            }
            catch (UnauthorizedAccessException)
            {
                throw; // do not retry auth failures
            }
            catch (Exception ex) when (attempt < _options.MaxRetries)
            {
                last = ex;
                var delay = TimeSpan.FromSeconds(Math.Pow(2, attempt)); // 1,2,4,8s
                await Task.Delay(delay, ct);
            }
        }

        throw last ?? new HttpRequestException($"POST {path} failed.");
    }

    private static string AgentVersion() =>
        typeof(ApiClient).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";
}
