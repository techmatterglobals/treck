using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Storage;
using Xunit;

namespace Treck.Agent.Tests;

public class FileDeviceIdStoreTests
{
    [Fact]
    public void GetOrCreate_generates_a_valid_uuid_and_persists_it()
    {
        using var paths = new TempPaths();
        var store = new FileDeviceIdStore(paths, NullLogger<FileDeviceIdStore>.Instance);

        var id = store.GetOrCreate();

        Assert.True(Guid.TryParse(id, out _));
        Assert.True(File.Exists(Path.Combine(paths.BaseDirectory, "device.id")));
    }

    [Fact]
    public void GetOrCreate_is_stable_across_calls_and_instances()
    {
        using var paths = new TempPaths();

        var first = new FileDeviceIdStore(paths, NullLogger<FileDeviceIdStore>.Instance).GetOrCreate();
        var sameInstanceAgain = new FileDeviceIdStore(paths, NullLogger<FileDeviceIdStore>.Instance).GetOrCreate();

        Assert.Equal(first, sameInstanceAgain);
    }

    [Fact]
    public void GetOrCreate_regenerates_when_file_content_is_invalid()
    {
        using var paths = new TempPaths();
        File.WriteAllText(Path.Combine(paths.BaseDirectory, "device.id"), "not-a-guid");

        var id = new FileDeviceIdStore(paths, NullLogger<FileDeviceIdStore>.Instance).GetOrCreate();

        Assert.True(Guid.TryParse(id, out _));
    }
}
