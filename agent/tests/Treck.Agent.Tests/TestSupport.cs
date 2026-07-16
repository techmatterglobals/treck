using Treck.Agent.Storage;

namespace Treck.Agent.Tests;

/// <summary>A temporary storage directory that cleans itself up.</summary>
internal sealed class TempPaths : IStoragePathProvider, IDisposable
{
    public string BaseDirectory { get; }

    public TempPaths()
    {
        BaseDirectory = Path.Combine(Path.GetTempPath(), "treck-tests-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(BaseDirectory);
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(BaseDirectory, recursive: true);
        }
        catch
        {
            // best-effort cleanup
        }
    }
}
