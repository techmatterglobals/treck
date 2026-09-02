namespace Treck.Agent.Api;

/// <summary>
/// The server rejected the enrollment code (HTTP 422): invalid, expired,
/// already used, or revoked. The message is safe to surface — it never contains
/// the submitted code.
/// </summary>
public sealed class EnrollmentRejectedException : ApiException
{
    public EnrollmentRejectedException(string message)
        : base(message, 422)
    {
    }
}
