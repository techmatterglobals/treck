using Microsoft.Extensions.Logging.Abstractions;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Screenshots;
using Xunit;

namespace Treck.Agent.Tests;

/// <summary>
/// Phase 8 — screenshot upload step. Verifies temp-file handling: a successful
/// upload deletes the file, a failed upload keeps it for retry, and a missing
/// file is treated as done so the queue never wedges. OS-agnostic (no capture).
/// </summary>
public class ScreenshotSyncServiceTests
{
    private sealed class FakeApi : ITreckApiClient
    {
        private readonly bool _result;

        public int ScreenshotCalls { get; private set; }

        public byte[]? LastBytes { get; private set; }

        public FakeApi(bool result) => _result = result;

        public Task<RegisterDeviceResponse> RegisterDeviceAsync(RegisterDeviceRequest request, CancellationToken cancellationToken)
            => throw new NotSupportedException();

        public Task<bool> UploadEventAsync(string bearerToken, OfflineEventPayload payload, CancellationToken cancellationToken)
            => throw new NotSupportedException();

        public Task<bool> UploadScreenshotAsync(string bearerToken, ScreenshotMetadata metadata, byte[] imageBytes, CancellationToken cancellationToken)
        {
            ScreenshotCalls++;
            LastBytes = imageBytes;
            return Task.FromResult(_result);
        }
    }

    private static ScreenshotMetadata MetadataFor(string path) => new(
        CapturedAt: DateTimeOffset.UnixEpoch,
        MonitorNumber: 0,
        Width: 100,
        Height: 100,
        FileSize: 3,
        ImageHash: "abc",
        ActiveProcess: "Code.exe",
        ActiveWindowTitle: "x",
        SessionId: "s1",
        LocalPath: path,
        Format: "jpeg");

    private static string WriteTemp(string content)
    {
        var path = Path.Combine(Path.GetTempPath(), "treck-ss-" + Guid.NewGuid().ToString("N") + ".jpg");
        File.WriteAllText(path, content);
        return path;
    }

    [Fact]
    public async Task Successful_upload_deletes_the_temp_file()
    {
        var path = WriteTemp("img");
        var api = new FakeApi(result: true);
        var sut = new ScreenshotSyncService(api, NullLogger<ScreenshotSyncService>.Instance);

        var ok = await sut.UploadAsync("token", MetadataFor(path), CancellationToken.None);

        Assert.True(ok);
        Assert.Equal(1, api.ScreenshotCalls);
        Assert.False(File.Exists(path), "temp file should be deleted after a successful upload");
    }

    [Fact]
    public async Task Failed_upload_keeps_the_temp_file()
    {
        var path = WriteTemp("img");
        var api = new FakeApi(result: false);
        var sut = new ScreenshotSyncService(api, NullLogger<ScreenshotSyncService>.Instance);

        try
        {
            var ok = await sut.UploadAsync("token", MetadataFor(path), CancellationToken.None);

            Assert.False(ok);
            Assert.True(File.Exists(path), "temp file must survive a failed upload for retry");
        }
        finally
        {
            File.Delete(path);
        }
    }

    [Fact]
    public async Task Missing_temp_file_is_treated_as_done_without_calling_the_api()
    {
        var api = new FakeApi(result: true);
        var sut = new ScreenshotSyncService(api, NullLogger<ScreenshotSyncService>.Instance);

        var ok = await sut.UploadAsync("token", MetadataFor("/no/such/file.jpg"), CancellationToken.None);

        Assert.True(ok);
        Assert.Equal(0, api.ScreenshotCalls);
    }

    [Fact]
    public async Task Uploads_the_temp_file_bytes()
    {
        var path = WriteTemp("hello-bytes");
        var api = new FakeApi(result: true);
        var sut = new ScreenshotSyncService(api, NullLogger<ScreenshotSyncService>.Instance);

        await sut.UploadAsync("token", MetadataFor(path), CancellationToken.None);

        Assert.Equal("hello-bytes", System.Text.Encoding.UTF8.GetString(api.LastBytes!));
    }
}
