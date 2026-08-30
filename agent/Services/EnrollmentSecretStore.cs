using Microsoft.Extensions.Logging;

namespace Treck.Agent.Services;

public sealed class EnrollmentSecretStore : IEnrollmentSecretStore
{
    public const string EnvironmentVariable = "TRECK_AGENT_ENROLLMENT_SECRET";
    public const string FileName = "enrollment.key";

    private readonly string _path;
    private readonly ILogger<EnrollmentSecretStore> _logger;

    public EnrollmentSecretStore(ILogger<EnrollmentSecretStore> logger)
    {
        _path = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "TreckAgent",
            FileName);
        _logger = logger;
    }

    public string? TryLoad()
    {
        var fromEnvironment = Environment.GetEnvironmentVariable(EnvironmentVariable);
        if (!string.IsNullOrWhiteSpace(fromEnvironment))
        {
            return fromEnvironment.Trim();
        }

        if (!File.Exists(_path))
        {
            return null;
        }

        var secret = File.ReadAllText(_path).Trim();
        return string.IsNullOrWhiteSpace(secret) ? null : secret;
    }

    public void DeleteFileSecret()
    {
        if (!File.Exists(_path))
        {
            return;
        }

        try
        {
            File.Delete(_path);
            _logger.LogInformation("Deleted one-time enrollment secret file.");
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Could not delete enrollment secret file {Path}.", _path);
        }
    }
}
