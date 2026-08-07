using Treck.Agent.Storage;

namespace Treck.Agent.Tests;

/// <summary>A time source that can be set/advanced deterministically in tests.</summary>
internal sealed class MutableTimeProvider : TimeProvider
{
    public DateTimeOffset UtcNow = DateTimeOffset.UnixEpoch;

    public override DateTimeOffset GetUtcNow() => UtcNow;

    public void Advance(TimeSpan by) => UtcNow = UtcNow.Add(by);
}

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
