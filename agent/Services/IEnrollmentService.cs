namespace Treck.Agent.Services;

/// <summary>
/// One-shot device enrollment (the installer / `--enroll` flow). Redeems an
/// enrollment code, stores the returned device credential, and exits. It never
/// starts the monitoring loop.
/// </summary>
public interface IEnrollmentService
{
    /// <summary>
    /// Run enrollment once. Returns a process exit code
    /// (see <see cref="EnrollmentExitCode"/>): 0 on success, non-zero on failure.
    /// </summary>
    Task<int> RunAsync(string? code, bool force, CancellationToken cancellationToken);
}

/// <summary>Process exit codes for the one-shot enrollment command.</summary>
public static class EnrollmentExitCode
{
    public const int Success = 0;
    public const int UsageError = 1;        // no code provided
    public const int AlreadyEnrolled = 2;   // token exists and --force not given
    public const int CodeRejected = 3;      // 422: invalid/expired/used/revoked
    public const int ServerUnavailable = 4; // network / non-422 server error
    public const int InvalidResponse = 5;   // 2xx but no usable token
}
