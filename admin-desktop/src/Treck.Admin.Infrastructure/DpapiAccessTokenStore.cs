using System.Security.Cryptography;
using System.Text;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Infrastructure;

public sealed class DpapiAccessTokenStore : IAccessTokenStore
{
    private readonly string _path;

    public DpapiAccessTokenStore()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TreckAdmin");
        _path = Path.Combine(directory, "session.dat");
    }

    public async Task<string?> ReadAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_path))
        {
            return null;
        }

        try
        {
            var encrypted = await File.ReadAllBytesAsync(_path, cancellationToken);
            var clear = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(clear);
        }
        catch (CryptographicException)
        {
            // A token copied from another Windows profile or damaged on disk is
            // not recoverable. Remove it and require a clean sign-in.
            File.Delete(_path);
            return null;
        }
    }

    public async Task WriteAsync(string token, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(token);
        var directory = Path.GetDirectoryName(_path)!;
        Directory.CreateDirectory(directory);

        var clear = Encoding.UTF8.GetBytes(token);
        var encrypted = ProtectedData.Protect(clear, null, DataProtectionScope.CurrentUser);
        await File.WriteAllBytesAsync(_path, encrypted, cancellationToken);
    }

    public Task ClearAsync(CancellationToken cancellationToken = default)
    {
        cancellationToken.ThrowIfCancellationRequested();
        if (File.Exists(_path))
        {
            File.Delete(_path);
        }

        return Task.CompletedTask;
    }
}
