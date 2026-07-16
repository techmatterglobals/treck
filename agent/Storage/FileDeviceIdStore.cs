using Microsoft.Extensions.Logging;

namespace Treck.Agent.Storage;

/// <summary>
/// Persists the device id as plain text in `device.id` (the id is an opaque
/// identifier, not a secret). Generates a UUID v4 on first run.
/// </summary>
public sealed class FileDeviceIdStore : IDeviceIdStore
{
    private const string FileName = "device.id";

    private readonly string _path;
    private readonly ILogger<FileDeviceIdStore> _logger;

    public FileDeviceIdStore(IStoragePathProvider paths, ILogger<FileDeviceIdStore> logger)
    {
        _path = Path.Combine(paths.BaseDirectory, FileName);
        _logger = logger;
    }

    public string GetOrCreate()
    {
        if (File.Exists(_path))
        {
            var existing = File.ReadAllText(_path).Trim();
            if (Guid.TryParse(existing, out _))
            {
                return existing;
            }

            _logger.LogWarning("device.id was present but invalid; regenerating.");
        }

        // Guid.NewGuid() produces a RFC 4122 version-4 (random) UUID.
        var deviceId = Guid.NewGuid().ToString("D");
        File.WriteAllText(_path, deviceId);
        _logger.LogInformation("Generated new device id {DeviceId}", deviceId);

        return deviceId;
    }
}
