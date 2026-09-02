using System.Net;

namespace Treck.Admin.Application.Errors;

public sealed class TreckApiException : Exception
{
    public TreckApiException(HttpStatusCode statusCode, string message, string? code = null) : base(message)
    {
        StatusCode = statusCode;
        Code = code;
    }

    public HttpStatusCode StatusCode { get; }
    public string? Code { get; }
    public bool IsUnauthorized => StatusCode == HttpStatusCode.Unauthorized;
    public bool IsForbidden => StatusCode == HttpStatusCode.Forbidden;
    public bool IsOrganizationContextError => Code is "organization_required" or "organization_inactive" or "organization_forbidden";
}
