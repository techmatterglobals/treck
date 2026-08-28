namespace Treck.Agent.Api;

/// <summary>An API call returned a non-success response.</summary>
public class ApiException : Exception
{
    public int? StatusCode { get; }

    public ApiException(string message, int? statusCode = null)
        : base(message)
    {
        StatusCode = statusCode;
    }

    public static ApiException FromStatus(int statusCode)
        => statusCode == 401
            ? new UnauthorizedApiException()
            : new ApiException($"API request failed with status {statusCode}.", statusCode);
}

/// <summary>
/// The device/user token was rejected (401). Callers use this to trigger
/// re-registration (see IDeviceRegistrationService.ReRegisterAsync).
/// </summary>
public sealed class UnauthorizedApiException : ApiException
{
    public UnauthorizedApiException()
        : base("Unauthorized (401).", 401)
    {
    }
}
