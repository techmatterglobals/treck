using System.Security.Cryptography;
using System.Text;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Infrastructure;

public sealed class DpapiSelectedOrganizationStore : ISelectedOrganizationStore
{
    private readonly string _path;

    public DpapiSelectedOrganizationStore()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TreckAdmin");
        _path = Path.Combine(directory, "selected-organization.dat");
    }

    public async Task<long?> ReadAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_path))
        {
            return null;
        }

        try
        {
            var encrypted = await File.ReadAllBytesAsync(_path, cancellationToken);
            var clear = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.CurrentUser);
            var value = Encoding.UTF8.GetString(clear);

            return long.TryParse(value, out var organizationId) ? organizationId : null;
        }
        catch (CryptographicException)
        {
            File.Delete(_path);
            return null;
        }
    }

    public async Task WriteAsync(long organizationId, CancellationToken cancellationToken = default)
    {
        var directory = Path.GetDirectoryName(_path)!;
        Directory.CreateDirectory(directory);

        var clear = Encoding.UTF8.GetBytes(organizationId.ToString(System.Globalization.CultureInfo.InvariantCulture));
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
