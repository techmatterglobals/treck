using Microsoft.Extensions.Logging;
using Treck.Agent.Security;

namespace Treck.Agent.Storage;

/// <summary>
/// Stores the device bearer token in `token.dat`, encrypted via
/// <see cref="ITokenProtector"/> (DPAPI). Only ciphertext is ever written —
/// the plaintext token never touches disk.
/// </summary>
public sealed class DpapiTokenStore : ITokenStore
{
    private const string FileName = "token.dat";

    private readonly string _path;
    private readonly ITokenProtector _protector;
    private readonly ILogger<DpapiTokenStore> _logger;

    public DpapiTokenStore(IStoragePathProvider paths, ITokenProtector protector, ILogger<DpapiTokenStore> logger)
    {
        _path = Path.Combine(paths.BaseDirectory, FileName);
        _protector = protector;
        _logger = logger;
    }

    public bool HasToken => File.Exists(_path);

    public string? TryLoad()
    {
        if (!File.Exists(_path))
        {
            return null;
        }

        try
        {
            return _protector.Unprotect(File.ReadAllBytes(_path));
        }
        catch (Exception ex)
        {
            // Corrupt / undecryptable (e.g. copied from another machine) → drop it
            // so the agent re-registers cleanly.
            _logger.LogWarning(ex, "Stored token could not be decrypted; clearing it.");
            Clear();
            return null;
        }
    }

    public void Save(string token)
    {
        File.WriteAllBytes(_path, _protector.Protect(token));
    }

    public void Clear()
    {
        if (File.Exists(_path))
        {
            File.Delete(_path);
        }
    }
}
