using Microsoft.Extensions.Options;
using Treck.Agent.Configuration;

namespace Treck.Agent.Storage;

/// <summary>
/// Defaults to %ProgramData%\TreckAgent (readable/writable by the machine
/// service), overridable via AgentOptions.StoragePath. Ensures the directory
/// exists on construction.
/// </summary>
public sealed class StoragePathProvider : IStoragePathProvider
{
    public string BaseDirectory { get; }

    public StoragePathProvider(IOptions<AgentOptions> options)
    {
        var configured = options.Value.StoragePath;

        BaseDirectory = string.IsNullOrWhiteSpace(configured)
            ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TreckAgent")
            : configured;

        Directory.CreateDirectory(BaseDirectory);
    }
}
