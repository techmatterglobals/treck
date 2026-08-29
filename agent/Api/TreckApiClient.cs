using System.Globalization;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Models;
using Treck.Agent.Screenshots;

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
        // Diagnostic: log the outgoing registration request with the enrollment
        // secret masked (never log the secret). This surfaces exactly which
        // EmployeeCode/DeviceUuid the agent is sending - e.g. a stale
        // appsettings value - without a server round-trip.
        _logger.LogInformation(
            "Registering device: DeviceUuid={DeviceUuid} EmployeeCode={EmployeeCode} ComputerName={ComputerName} Os={Os} AgentVersion={AgentVersion} EnrollmentSecret={SecretMask}",
            request.DeviceUuid,
            request.EmployeeCode,
            request.ComputerName,
            request.Os,
            request.AgentVersion,
            Mask(request.EnrollmentSecret));

        using var response = await _http.PostAsJsonAsync("api/agent/register", request, JsonOptions, cancellationToken);

        if (!response.IsSuccessStatusCode)
        {
            // Log the status AND the response body so validation errors (e.g.
            // "The selected employee code is invalid.") are visible in the log.
            var body = await SafeReadBodyAsync(response, cancellationToken);

            _logger.LogError(
                "Device registration HTTP {Status} for device {DeviceUuid}. Response: {Body}",
                (int)response.StatusCode,
                request.DeviceUuid,
                body);

            throw ApiException.FromStatus((int)response.StatusCode);
        }

        var envelope = await response.Content.ReadFromJsonAsync<ApiEnvelope<RegisterDeviceResponse>>(JsonOptions, cancellationToken);

        return envelope?.Data
            ?? throw new ApiException("Registration succeeded but the response body was empty.");
    }

    public async Task<AgentConfigResponse> GetAgentConfigAsync(string bearerToken, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "api/agent/config");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);

        using var response = await _http.SendAsync(request, cancellationToken);

        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new UnauthorizedApiException();
        }

        if (!response.IsSuccessStatusCode)
        {
            throw ApiException.FromStatus((int)response.StatusCode);
        }

        var envelope = await response.Content.ReadFromJsonAsync<ApiEnvelope<AgentConfigResponse>>(JsonOptions, cancellationToken);

        return envelope?.Data
            ?? throw new ApiException("Config fetch succeeded but the response body was empty.");
    }

    public async Task<bool> ReportHealthAsync(
        string bearerToken,
        AgentHealthReportRequest request,
        CancellationToken cancellationToken)
    {
        using var message = new HttpRequestMessage(HttpMethod.Post, "api/agent/health")
        {
            Content = JsonContent.Create(request, options: JsonOptions),
        };
        message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);

        using var response = await _http.SendAsync(message, cancellationToken);

        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new UnauthorizedApiException();
        }

        return response.IsSuccessStatusCode;
    }

    public async Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, "api/agent/events")
        {
            Content = JsonContent.Create(payload, options: JsonOptions),
        };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);

        using var response = await _http.SendAsync(request, cancellationToken);

        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new UnauthorizedApiException();
        }

        return response.IsSuccessStatusCode;
    }

    public async Task<bool> UploadScreenshotAsync(
        string bearerToken,
        ScreenshotMetadata metadata,
        byte[] imageBytes,
        CancellationToken cancellationToken)
    {
        var contentType = metadata.Format == "png" ? "image/png" : "image/jpeg";
        var fileName = $"{metadata.ImageHash}.{(metadata.Format == "png" ? "png" : "jpg")}";

        using var form = new MultipartFormDataContent();

        var imageContent = new ByteArrayContent(imageBytes);
        imageContent.Headers.ContentType = new MediaTypeHeaderValue(contentType);
        form.Add(imageContent, "image", fileName);

        form.Add(new StringContent(metadata.CapturedAt.ToString("o", CultureInfo.InvariantCulture)), "captured_at");
        form.Add(new StringContent(metadata.MonitorNumber.ToString(CultureInfo.InvariantCulture)), "monitor_number");
        form.Add(new StringContent(metadata.Width.ToString(CultureInfo.InvariantCulture)), "width");
        form.Add(new StringContent(metadata.Height.ToString(CultureInfo.InvariantCulture)), "height");
        form.Add(new StringContent(metadata.ImageHash), "image_hash");
        form.Add(new StringContent(metadata.SessionId), "session_id");

        if (!string.IsNullOrEmpty(metadata.ActiveProcess))
        {
            form.Add(new StringContent(metadata.ActiveProcess), "active_process");
        }

        if (!string.IsNullOrEmpty(metadata.ActiveWindowTitle))
        {
            form.Add(new StringContent(metadata.ActiveWindowTitle), "active_window_title");
        }

        // Event source metadata (Phase 8 #3): where the capture was collected.
        form.Add(new StringContent(metadata.SourceSessionId.ToString(CultureInfo.InvariantCulture)), "source_session_id");

        if (!string.IsNullOrEmpty(metadata.SourceUser))
        {
            form.Add(new StringContent(metadata.SourceUser), "source_user");
            // Explicit alias for the backend's employee resolver (Phase 11).
            form.Add(new StringContent(metadata.SourceUser), "windows_username");
        }

        if (!string.IsNullOrEmpty(metadata.SourceProcess))
        {
            form.Add(new StringContent(metadata.SourceProcess), "source_process");
        }

        if (!string.IsNullOrEmpty(metadata.CollectionMode))
        {
            form.Add(new StringContent(metadata.CollectionMode), "collection_mode");
        }

        using var request = new HttpRequestMessage(HttpMethod.Post, "api/agent/screenshots") { Content = form };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        using var response = await _http.SendAsync(request, cancellationToken);

        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new UnauthorizedApiException();
        }

        return response.IsSuccessStatusCode;
    }

    /// <summary>Reveal only the length of a secret, never its value.</summary>
    private static string Mask(string? secret)
        => string.IsNullOrEmpty(secret) ? "(empty)" : $"***({secret.Length} chars)";

    /// <summary>Read a (possibly error) response body for logging; never throws.</summary>
    private static async Task<string> SafeReadBodyAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        try
        {
            var body = await response.Content.ReadAsStringAsync(cancellationToken);

            return body.Length > 2000 ? body[..2000] + "…(truncated)" : body;
        }
        catch (Exception ex)
        {
            return $"(could not read body: {ex.Message})";
        }
    }
}
