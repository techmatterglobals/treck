using System.Security.Cryptography;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Treck.Agent.Downloads;

/// <summary>
/// Computes a SHA-256 for a completed download when hashing is enabled and the
/// file is within the configured size cap (Phase 12). Reads the file only to
/// hash it — the bytes are never retained or transmitted; only the resulting
/// digest is reported. Returns null when disabled, too large, or unreadable.
/// </summary>
public interface IFileHashService
{
    string? TryHash(string path, long size);
}

public sealed class FileHashService : IFileHashService
{
    private readonly FileDownloadOptions _options;
    private readonly ILogger<FileHashService> _logger;

    public FileHashService(IOptions<FileDownloadOptions> options, ILogger<FileHashService> logger)
    {
        _options = options.Value;
        _logger = logger;
    }

    public string? TryHash(string path, long size)
    {
        if (!_options.HashEnabled)
        {
            return null;
        }

        if (_options.MaxHashBytes > 0 && size > _options.MaxHashBytes)
        {
            _logger.LogDebug("Skipping hash for {Path}: {Size} bytes exceeds cap.", path, size);
            return null;
        }

        try
        {
            using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
            using var sha = SHA256.Create();
            var hash = sha.ComputeHash(stream);

            return Convert.ToHexString(hash).ToLowerInvariant();
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Could not hash {Path}.", path);
            return null;
        }
    }
}
