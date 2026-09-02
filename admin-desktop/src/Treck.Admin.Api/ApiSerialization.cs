using System.Net.Http.Json;
using System.Text.Json;
using Treck.Admin.Application.Errors;

namespace Treck.Admin.Api;

internal static class ApiSerialization
{
    internal static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
    };

    internal static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode) return;

        string message;
        string? code = null;
        try
        {
            var error = await response.Content.ReadFromJsonAsync<ErrorEnvelope>(JsonOptions, cancellationToken);
            message = error?.Message ?? "The Treck server rejected the request.";
            code = error?.Code;
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            message = "The Treck server returned an invalid error response.";
        }

        throw new TreckApiException(response.StatusCode, message, code);
    }

    private sealed record ErrorEnvelope(string Message, string? Code);
}
